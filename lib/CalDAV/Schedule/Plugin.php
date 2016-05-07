<?php

namespace Sabre\CalDAV\Schedule;

use DateTimeZone;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\Sharing;
use Sabre\DAV\PropFind;
use Sabre\DAV\PropPatch;
use Sabre\DAV\INode;
use Sabre\DAV\Xml\Property\Href;
use Sabre\DAV\Xml\Property\LocalHref;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject;
use Sabre\VObject\Reader;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\ITip;
use Sabre\VObject\ITip\Message;
use Sabre\DAVACL;
use Sabre\CalDAV\ICalendar;
use Sabre\CalDAV\ICalendarObject;
use Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\NotImplemented;

use Sabre\DAV;
use Sabre\CalDAV;
use Sabre\DAV\MkCol;
use Sabre\HTTP;
use Sabre\Uri;


/**
 * CalDAV scheduling plugin.
 * =========================
 *
 * This plugin provides the functionality added by the "Scheduling Extensions
 * to CalDAV" standard, as defined in RFC6638.
 *
 * calendar-auto-schedule largely works by intercepting a users request to
 * update their local calendar. If a user creates a new event with attendees,
 * this plugin is supposed to grab the information from that event, and notify
 * the attendees of this.
 *
 * There's 3 possible transports for this:
 * * local delivery
 * * delivery through email (iMip)
 * * server-to-server delivery (iSchedule)
 *
 * iMip is simply, because we just need to add the iTip message as an email
 * attachment. Local delivery is harder, because we both need to add this same
 * message to a local DAV inbox, as well as live-update the relevant events.
 *
 * iSchedule is something for later.
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
class Plugin extends ServerPlugin {

    /**
     * This is the official CalDAV namespace
     */
    const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';

    /**
     * Reference to main Server object.
     *
     * @var Server
     */
    protected $server;

    /**
     * Returns a list of features for the DAV: HTTP header.
     *
     * @return array
     */
    function getFeatures() {

        return ['calendar-auto-schedule', 'calendar-availability'];

    }

    /**
     * Returns the name of the plugin.
     *
     * Using this name other plugins will be able to access other plugins
     * using Server::getPlugin
     *
     * @return string
     */
    function getPluginName() {

        return 'caldav-schedule';

    }

    /**
     * Initializes the plugin
     *
     * @param Server $server
     * @return void
     */
    function initialize(Server $server) {

        $this->server = $server;
        $server->on('method:POST',              [$this, 'httpPost']);
        $server->on('afterMethod:GET',          [$this, 'httpAfterGet']);
        $server->on('afterMethod:PUT',          [$this, 'httpAfterPut']);
        $server->on('beforeWriteContent',       [$this, 'beforeWriteContent'], 50);
        $server->on('propFind',                 [$this, 'propFind']);
        $server->on('propPatch',                [$this, 'propPatch']);
        $server->on('calendarObjectChange',     [$this, 'calendarObjectChange']);
        $server->on('beforeUnbind',             [$this, 'beforeUnbind']);
        $server->on('schedule',                 [$this, 'scheduleLocalDelivery']);
        $server->on('getSupportedPrivilegeSet', [$this, 'getSupportedPrivilegeSet']);

        $ns = '{' . self::NS_CALDAV . '}';

        /**
         * This information ensures that the {DAV:}resourcetype property has
         * the correct values.
         */
        $server->resourceTypeMapping['\\Sabre\\CalDAV\\Schedule\\IOutbox'] = $ns . 'schedule-outbox';
        $server->resourceTypeMapping['\\Sabre\\CalDAV\\Schedule\\IInbox'] = $ns . 'schedule-inbox';

        /**
         * Properties we protect are made read-only by the server.
         */
        array_push($server->protectedProperties,
            $ns . 'schedule-inbox-URL',
            $ns . 'schedule-outbox-URL',
            $ns . 'calendar-user-address-set',
            $ns . 'calendar-user-type',
            $ns . 'schedule-default-calendar-URL'
        );

    }

    /**
     * Returns the standard users' principal.
     *
     * This is one authorative principal url for the current user.
     * This method will return null if the user wasn't logged in.
     *
     * @return string|null
     */
    function getCurrentUserPrincipalAlternateUriSet() {

        /** @var $authPlugin Sabre\DAV\Auth\Plugin */
        $authPlugin = $this->server->getPlugin('auth');
        if (!$authPlugin) {
            return null;
        }
        $currentUser=$authPlugin->getCurrentPrincipal();

        $result = $this->server->getPropertiesForPath($currentUser, ['{DAV:}alternate-URI-set']);
        $result = $result[0];

        if (isset($result[200]['{DAV:}alternate-URI-set']))
            return $result[200]['{DAV:}alternate-URI-set']->getHrefs();
        
        return null;
    }

    /**
     * Use this method to tell the server this plugin defines additional
     * HTTP methods.
     *
     * This method is passed a uri. It should only return HTTP methods that are
     * available for the specified uri.
     *
     * @param string $uri
     * @return array
     */
    function getHTTPMethods($uri) {

        try {
            $node = $this->server->tree->getNodeForPath($uri);
        } catch (NotFound $e) {
            return [];
        }

        if ($node instanceof IOutbox) {
            return ['POST'];
        }

        return [];

    }

    /**
     * This method handles POST request for the outbox.
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return bool
     */
    function httpPost(RequestInterface $request, ResponseInterface $response) {

        // Checking if this is a text/calendar content type
        $contentType = $request->getHeader('Content-Type');
        if (strpos($contentType, 'text/calendar') !== 0) {
            return;
        }

        $path = $request->getPath();

        // Checking if we're talking to an outbox
        try {
            $node = $this->server->tree->getNodeForPath($path);
        } catch (NotFound $e) {
            return;
        }
        if (!$node instanceof IOutbox)
            return;

        $this->server->transactionType = 'post-caldav-outbox';
        $this->outboxRequest($node, $request, $response);

        // Returning false breaks the event chain and tells the server we've
        // handled the request.
        return false;

    }

    /**
     * This event is triggered after GET requests.
     *
     * This is used to add Schedule-Tag HTTP header.
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return void
     */
    function httpAfterGet(RequestInterface $request, ResponseInterface $response) {

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);

        if (!$node instanceof ICalendarObject) return;

        $scheduleTag = $node->getScheduleTag();
        if (isset($scheduleTag))
            $response->addHeader('Schedule-Tag', $scheduleTag);
        
        return;

    }

    /**
     * This event is triggered after PUT requests.
     *
     * This is used to add Schedule-Tag HTTP header.
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return void
     */
    function httpAfterPut(RequestInterface $request, ResponseInterface $response) {

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);

        if (!$node instanceof ICalendarObject) return;

        $scheduleTag = $node->getScheduleTag();
        if (isset($scheduleTag))
            $response->addHeader('Schedule-Tag', $scheduleTag);
        
        return;

    }


    protected static $significantproperties = array('DTSTART', 'DTEND', 'DUE', 'RDATE', 'RRULE', 'EXDATE', 'EXRULE', 'STATUS');

    /**
     * Merge a submitted iCalendar data $data with the PARTSTAT
     * info from the existing calendar object $node (!$hasLatentException),
     * or use the server version with only the new user partstat changed
     * ($hasLatentException).
     *
     * An exception is thrown if it's not.
     *
     * @param DAV\IFile $node
     * @param resource|string $data
     * @param string $path
     * @param bool $modified Should be set to true, if this event handler
     *                       changed &$data.
     * @param RequestInterface $request The http request.
     * @param ResponseInterface $response The http response.
     * @param bool $hasLatentException There is a latent Exception waiting
    *                        to be thrown if the conflict's not solved.
     * @return bool
     */
    protected function mergeICalendar($node, &$data, $path, &$modified, RequestInterface $request, ResponseInterface $response, $hasLatentException) {

        // If it's a stream, we convert it to a string first.
        if (is_resource($data)) {
            $data = stream_get_contents($data);
        }

        $before = $data;

        try {

            // If the data starts with a [, we can reasonably assume we're dealing
            // with a jCal object.
            if (substr($data, 0, 1) === '[') {
                $newObj = VObject\Reader::readJson($data);

                // Converting $data back to iCalendar, as that's what we
                // technically support everywhere.
                $data = $newObj->serialize();
                $modified = true;
            } else {
                $newObj = VObject\Reader::read($data);
            }

            $srvObj = VObject\Reader::read($node->get());

        } catch (VObject\ParseException $e) {

            throw new DAV\Exception\UnsupportedMediaType('This resource only supports valid iCalendar 2.0 data. Parse error: ' . $e->getMessage());

        }

        if ($newObj->name !== 'VCALENDAR') {
            throw new DAV\Exception\UnsupportedMediaType('This collection can only support iCalendar objects.');
        }

        // If there's a significant difference between the server and new event:
        // - If there's a latent PreconditionFailed exception, return FALSE to throw the exception.
        // - Otherwise, it means the organizer is updating the event,
        //   so ATTENDEEs have to be reset: return TRUE to further process the significant update.
        
        /* Removed to ignore clients that increase SEQUENCE for no reason (DAVdroid)
        if (isset($newObj->VEVENT->SEQUENCE) && isset($srvObj->VEVENT->SEQUENCE)) {
            if ($newObj->VEVENT->SEQUENCE->getValue() != $srvObj->VEVENT->SEQUENCE->getValue()) {
                $newObj->destroy();
                $srvObj->destroy();
                return !$hasLatentException;
            }
        } else {
        */
        foreach(self::$significantproperties as $prop) {
            if(isset($newObj->$prop) && isset($srvObj->$prop)) {
                if ($newObj->VEVENT->$prop->getValue() != $srvObj->VEVENT->$prop->getValue()){ // Timezone-proof comparison?
                    $newObj->destroy();
                    $srvObj->destroy();
                    return !$hasLatentException;
                }
            }
        }
        /*}*/

        // Actual merge:

        $currentUserAltUriSet = $this->getCurrentUserPrincipalAlternateUriSet();

        if (!$hasLatentException) {

            // We're here because the user has done a PUT request,
            // and the server may have processed some attendee
            // participation status in the meantime (and only that).
            // So we need to report these changes (except the user itself).

            // We're not using setValue(). OK?

            if (isset($newObj->VEVENT->ORGANIZER) && isset($srvObj->VEVENT->ORGANIZER)) {
                if (!in_array($newObj->VEVENT->ORGANIZER->getValue(), $currentUserAltUriSet)) {
                    if (isset($srvObj->VEVENT->ORGANIZER['SCHEDULE-STATUS']))
                        $newObj->VEVENT->ORGANIZER['SCHEDULE-STATUS'] = $srvObj->VEVENT->ORGANIZER['SCHEDULE-STATUS'];
                    if (isset($srvObj->VEVENT->ORGANIZER['PARTSTAT']))
                        $newObj->VEVENT->ORGANIZER['PARTSTAT'] = $srvObj->VEVENT->ORGANIZER['PARTSTAT'];
                    if (isset($srvObj->VEVENT->ORGANIZER['RSVP']))
                        $newObj->VEVENT->ORGANIZER['RSVP'] = $srvObj->VEVENT->ORGANIZER['RSVP'];
                    else
                        unset($newObj->VEVENT->ORGANIZER['RSVP']);
                    if (isset($srvObj->VEVENT->ORGANIZER['SCHEDULE-FORCE-SEND']))
                        $newObj->VEVENT->ORGANIZER['SCHEDULE-FORCE-SEND'] = $srvObj->VEVENT->ORGANIZER['SCHEDULE-FORCE-SEND'];
                    else
                        unset($newObj->VEVENT->ORGANIZER['SCHEDULE-FORCE-SEND']);
             }
            }

            if (isset($newObj->VEVENT->ATTENDEE) && isset($srvObj->VEVENT->ATTENDEE)) {
                foreach ($newObj->VEVENT->ATTENDEE as $nkey => $newObjAttendee) {
                    foreach ($srvObj->VEVENT->ATTENDEE as $skey => $srvObjAttendee) {
                        if ($newObjAttendee->getNormalizedValue() === $srvObjAttendee->getNormalizedValue()) {
                            if (!in_array($newObj->VEVENT->ATTENDEE[$nkey]->getValue(), $currentUserAltUriSet)) {
    
                                if (isset($srvObj->VEVENT->ATTENDEE[$skey]['SCHEDULE-STATUS']))
                                    $newObj->VEVENT->ATTENDEE[$nkey]['SCHEDULE-STATUS'] = $srvObj->VEVENT->ATTENDEE[$skey]['SCHEDULE-STATUS'];
                                if (isset($srvObj->VEVENT->ATTENDEE[$skey]['PARTSTAT']))
                                    $newObj->VEVENT->ATTENDEE[$nkey]['PARTSTAT'] = $srvObj->VEVENT->ATTENDEE[$skey]['PARTSTAT'];
                                if (isset($srvObj->VEVENT->ATTENDEE[$skey]['RSVP']))
                                    $newObj->VEVENT->ATTENDEE[$nkey]['RSVP'] = $srvObj->VEVENT->ATTENDEE[$skey]['RSVP'];
                                else
                                    unset($newObj->VEVENT->ATTENDEE[$nkey]['RSVP']);
                                if (isset($srvObj->VEVENT->ATTENDEE[$skey]['SCHEDULE-FORCE-SEND']))
                                    $newObj->VEVENT->ATTENDEE[$nkey]['SCHEDULE-FORCE-SEND'] = $srvObj->VEVENT->ATTENDEE[$skey]['SCHEDULE-FORCE-SEND'];
                                else
                                    unset($newObj->VEVENT->ATTENDEE[$nkey]['SCHEDULE-FORCE-SEND']);
                            }
                        }
                    }
                }
            }

            $data = $newObj->serialize();

        } else {

            // We're here because the user has done a PUT request,
            // and a Precondition test failed,
            // but we want to take into account the new user PARTSTAT anyway.
            // (this is not standard yet useful)

            if (isset($srvObj->VEVENT->ORGANIZER) && isset($newObj->VEVENT->ORGANIZER)) {
                if (in_array($srvObj->VEVENT->ORGANIZER->getValue(), $currentUserAltUriSet)) {
                    if (isset($newObj->VEVENT->ORGANIZER['SCHEDULE-STATUS']))
                        $srvObj->VEVENT->ORGANIZER['SCHEDULE-STATUS'] = $newObj->VEVENT->ORGANIZER['SCHEDULE-STATUS'];
                    if (isset($newObj->VEVENT->ORGANIZER['PARTSTAT']))
                        $srvObj->VEVENT->ORGANIZER['PARTSTAT'] = $newObj->VEVENT->ORGANIZER['PARTSTAT'];
                    if (isset($newObj->VEVENT->ORGANIZER['RSVP']))
                        $srvObj->VEVENT->ORGANIZER['RSVP'] = $newObj->VEVENT->ORGANIZER['RSVP'];
                    else
                        unset($srvObj->VEVENT->ORGANIZER['RSVP']);
                    if (isset($newObj->VEVENT->ORGANIZER['SCHEDULE-FORCE-SEND']))
                        $srvObj->VEVENT->ORGANIZER['SCHEDULE-FORCE-SEND'] = $newObj->VEVENT->ORGANIZER['SCHEDULE-FORCE-SEND'];
                    else
                        unset($srvObj->VEVENT->ORGANIZER['SCHEDULE-FORCE-SEND']);
                }
            }

            if (isset($srvObj->VEVENT->ATTENDEE) && isset($newObj->VEVENT->ATTENDEE)) {
                foreach ($srvObj->VEVENT->ATTENDEE as $nkey => $srvObjAttendee) {
                    foreach ($newObj->VEVENT->ATTENDEE as $skey => $newObjAttendee) {
                        if ($srvObjAttendee->getNormalizedValue() === $newObjAttendee->getNormalizedValue()) {
                            if (in_array($srvObj->VEVENT->ATTENDEE[$nkey]->getValue(), $currentUserAltUriSet)) {

                                if (isset($newObj->VEVENT->ATTENDEE[$skey]['SCHEDULE-STATUS']))
                                    $srvObj->VEVENT->ATTENDEE[$nkey]['SCHEDULE-STATUS'] = $newObj->VEVENT->ATTENDEE[$skey]['SCHEDULE-STATUS'];
                                if (isset($newObj->VEVENT->ATTENDEE[$skey]['PARTSTAT']))
                                    $srvObj->VEVENT->ATTENDEE[$nkey]['PARTSTAT'] = $newObj->VEVENT->ATTENDEE[$skey]['PARTSTAT'];
                                if (isset($newObj->VEVENT->ATTENDEE[$skey]['RSVP']))
                                    $srvObj->VEVENT->ATTENDEE[$nkey]['RSVP'] = $newObj->VEVENT->ATTENDEE[$skey]['RSVP'];
                                else
                                    unset($srvObj->VEVENT->ATTENDEE[$nkey]['RSVP']);
                                if (isset($newObj->VEVENT->ATTENDEE[$skey]['SCHEDULE-FORCE-SEND']))
                                    $srvObj->VEVENT->ATTENDEE[$nkey]['SCHEDULE-FORCE-SEND'] = $newObj->VEVENT->ATTENDEE[$skey]['SCHEDULE-FORCE-SEND'];
                                else
                                    unset($srvObj->VEVENT->ATTENDEE[$nkey]['SCHEDULE-FORCE-SEND']);
                            }
                        }
                    }
                }
            }

            // Yes, we'll be returning the serialized SERVER calendar object,
            // not the user NEW one.
            $data = $srvObj->serialize();

        }

        $modified = true;

        // Destroy circular references so PHP will garbage collect the object.
        $newObj->destroy();
        $srvObj->destroy();
        return true;

    }

    /**
     * This method is triggered before a file gets updated with new content.
     *
     * This plugin handles If-Schedule-Tag-Match Precondition and resolve
     * PARTSTAT conflicts.
     *
     * @param string $path
     * @param DAV\IFile $node
     * @param resource $data
     * @param bool $modified Should be set to true, if this event handler
     *                       changed &$data.
     * @return void
     */
    function beforeWriteContent($path, DAV\IFile $node, &$data, &$modified) {

        if (!$node instanceof ICalendarObject)
            return;

        // We're only interested in ICalendarObject nodes that are inside of a
        // real calendar. This is to avoid triggering validation and scheduling
        // for non-calendars (such as an inbox).
        list($parent) = Uri\split($path);
        $parentNode = $this->server->tree->getNodeForPath($parent);

        if (!$parentNode instanceof ICalendar) {
            if(isset($this->server->latentPreconditionFailed))
                throw $this->server->latentPreconditionFailed; // Just in case.
            return;
        }

        if ($ifMatch = $this->server->httpRequest->getHeader('If-Schedule-Tag-Match')) {
            // If-Schedule-Tag-Match precondition failed
            if ($ifMatch != $node->getScheduleTag())
                if(!isset($this->server->latentPreconditionFailed))
                    $this->server->latentPreconditionFailed = new DAV\Exception\PreconditionFailed('An If-Schedule-Tag-Match header was specified, but doesn\'t match.', 'If-Schedule-Tag-Match');
        }

        if ($ifMatch || $this->server->latentPreconditionFailed) {
            // Merge current calendar object ATTENDEE with new object ATTENDEE
            $mergeSuccess = $this->mergeICalendar(
                $node,
                $data,
                $path,
                $modified,
                $this->server->httpRequest,
                $this->server->httpResponse,
                isset($this->server->latentPreconditionFailed)
            );

            // Avoids sending PreconditionFailed if the merge "went well"
            // Note: Even if it "went well", the merge may have actually ignored some user data
            //       (eg. conflicting with an organizer's update or since another shared calendar invitee update).
            //       But we don't throw an error since "I, server, (deemed to have) resolved the conflict.
            //       Don't you, client, try to resolve the conflict again or we might end up in an infinite loop."
            if(!$mergeSuccess)
                throw $this->server->latentPreconditionFailed;
        }
    }


    /**
     * This method handler is invoked during fetching of properties.
     *
     * We use this event to add calendar-auto-schedule-specific properties.
     *
     * @param PropFind $propFind
     * @param INode $node
     * @return void
     */
    function propFind(PropFind $propFind, INode $node) {

        if ($node instanceof DAVACL\IPrincipal) {

            $caldavPlugin = $this->server->getPlugin('caldav');
            $principalUrl = $node->getPrincipalUrl();

            // schedule-outbox-URL property
            $propFind->handle('{' . self::NS_CALDAV . '}schedule-outbox-URL', function() use ($principalUrl, $caldavPlugin) {

                $calendarHomePath = $caldavPlugin->getCalendarHomeForPrincipal($principalUrl);
                if (!$calendarHomePath) {
                    return null;
                }
                $outboxPath = $calendarHomePath . '/outbox/';

                return new LocalHref($outboxPath);

            });
            // schedule-inbox-URL property
            $propFind->handle('{' . self::NS_CALDAV . '}schedule-inbox-URL', function() use ($principalUrl, $caldavPlugin) {

                $calendarHomePath = $caldavPlugin->getCalendarHomeForPrincipal($principalUrl);
                if (!$calendarHomePath) {
                    return null;
                }
                $inboxPath = $calendarHomePath . '/inbox/';

                return new LocalHref($inboxPath);

            });

            $propFind->handle('{' . self::NS_CALDAV . '}schedule-default-calendar-URL', function() use ($principalUrl, $caldavPlugin) {

                // We don't support customizing this property yet, so in the
                // meantime we just grab the first calendar in the home-set.
                $calendarHomePath = $caldavPlugin->getCalendarHomeForPrincipal($principalUrl);

                if (!$calendarHomePath) {
                    return null;
                }

                $sccs = '{' . self::NS_CALDAV . '}supported-calendar-component-set';

                $result = $this->server->getPropertiesForPath($calendarHomePath, [
                    '{DAV:}resourcetype',
                    '{DAV:}share-access',
                    $sccs,
                ], 1);

                foreach ($result as $child) {
                    if (!isset($child[200]['{DAV:}resourcetype']) || !$child[200]['{DAV:}resourcetype']->is('{' . self::NS_CALDAV . '}calendar')) {
                        // Node is either not a calendar
                        continue;
                    }
                    if (isset($child[200]['{DAV:}share-access'])) {
                        $shareAccess = $child[200]['{DAV:}share-access']->getValue();
                        if ($shareAccess !== Sharing\Plugin::ACCESS_NOTSHARED && $shareAccess !== Sharing\Plugin::ACCESS_SHAREDOWNER) {
                            // Node is a shared node, not owned by the relevant
                            // user.
                            continue;
                        }

                    }
                    if (!isset($child[200][$sccs]) || in_array('VEVENT', $child[200][$sccs]->getValue())) {
                        // Either there is no supported-calendar-component-set
                        // (which is fine) or we found one that supports VEVENT.
                        return new LocalHref($child['href']);
                    }
                }

            });

            // The server currently reports every principal to be of type
            // 'INDIVIDUAL'
            $propFind->handle('{' . self::NS_CALDAV . '}calendar-user-type', function() {

                return 'INDIVIDUAL';

            });

        }

        if ($node instanceof ICalendarObject) {

            // schedule-tag property
            $propFind->handle('{' . self::NS_CALDAV . '}schedule-tag', function() use ($propFind, $node) {

                return $node->getScheduleTag();

            });

        }

        // Mapping the old property to the new property.
        $propFind->handle('{http://calendarserver.org/ns/}calendar-availability', function() use ($propFind, $node) {

             // In case it wasn't clear, the only difference is that we map the
            // old property to a different namespace.
             $availProp = '{' . self::NS_CALDAV . '}calendar-availability';
             $subPropFind = new PropFind(
                 $propFind->getPath(),
                 [$availProp]
             );

             $this->server->getPropertiesByNode(
                 $subPropFind,
                 $node
             );

             $propFind->set(
                 '{http://calendarserver.org/ns/}calendar-availability',
                 $subPropFind->get($availProp),
                 $subPropFind->getStatus($availProp)
             );

        });

    }

    /**
     * This method is called during property updates.
     *
     * @param string $path
     * @param PropPatch $propPatch
     * @return void
     */
    function propPatch($path, PropPatch $propPatch) {

        // Mapping the old property to the new property.
        $propPatch->handle('{http://calendarserver.org/ns/}calendar-availability', function($value) use ($path) {

            $availProp = '{' . self::NS_CALDAV . '}calendar-availability';
            $subPropPatch = new PropPatch([$availProp => $value]);
            $this->server->emit('propPatch', [$path, $subPropPatch]);
            $subPropPatch->commit();

            return $subPropPatch->getResult()[$availProp];

        });

    }

    /**
     * This method is triggered whenever there was a calendar object gets
     * created or updated.
     *
     * @param RequestInterface $request HTTP request
     * @param ResponseInterface $response HTTP Response
     * @param VCalendar $vCal Parsed iCalendar object
     * @param mixed $calendarPath Path to calendar collection
     * @param mixed $modified The iCalendar object has been touched.
     * @param mixed $isNew Whether this was a new item or we're updating one
     * @return void
     */
    function calendarObjectChange(RequestInterface $request, ResponseInterface $response, VCalendar $vCal, $calendarPath, &$modified, $isNew) {

        if (!$this->scheduleReply($this->server->httpRequest)) {
            return;
        }

        $calendarNode = $this->server->tree->getNodeForPath($calendarPath);

        $addresses = $this->getAddressesForPrincipal(
            $calendarNode->getOwner()
        );

        if (!$isNew) {
            $node = $this->server->tree->getNodeForPath($request->getPath());
            $oldObj = Reader::read($node->get());
        } else {
            $oldObj = null;
        }

        $this->processICalendarChange($oldObj, $vCal, $addresses, [], $modified);

        if ($oldObj) {
            // Destroy circular references so PHP will GC the object.
            $oldObj->destroy();
        }

    }

    /**
     * This method is responsible for delivering the ITip message.
     *
     * @param ITip\Message $itipMessage
     * @return void
     */
    function deliver(ITip\Message $iTipMessage) {

        $this->server->emit('schedule', [$iTipMessage]);
        if (!$iTipMessage->scheduleStatus) {
            $iTipMessage->scheduleStatus = '5.2;There was no system capable of delivering the scheduling message';
        }
        // In case the change was considered 'insignificant', we are going to
        // remove any error statuses, if any. See ticket #525.
        list($baseCode) = explode('.', $iTipMessage->scheduleStatus);
        if (!$iTipMessage->significantChange && in_array($baseCode, ['3', '5'])) {
            $iTipMessage->scheduleStatus = null;
        }

    }

    /**
     * This method is triggered before a file gets deleted.
     *
     * We use this event to make sure that when this happens, attendees get
     * cancellations, and organizers get 'DECLINED' statuses.
     *
     * @param string $path
     * @return void
     */
    function beforeUnbind($path) {

        // FIXME: We shouldn't trigger this functionality when we're issuing a
        // MOVE. This is a hack.
        if ($this->server->httpRequest->getMethod() === 'MOVE') return;

        $node = $this->server->tree->getNodeForPath($path);

        if (!$node instanceof ICalendarObject || $node instanceof ISchedulingObject) {
            return;
        }

        if (!$this->scheduleReply($this->server->httpRequest)) {
            return;
        }

        $addresses = $this->getAddressesForPrincipal(
            $node->getOwner()
        );

        $broker = new ITip\Broker();
        $messages = $broker->parseEvent(null, $addresses, $node->get());

        foreach ($messages as $message) {
            $this->deliver($message);
        }

    }

    /**
     * Event handler for the 'schedule' event.
     *
     * This handler attempts to look at local accounts to deliver the
     * scheduling object.
     *
     * @param ITip\Message $iTipMessage
     * @return void
     */
    function scheduleLocalDelivery(ITip\Message $iTipMessage) {

        $aclPlugin = $this->server->getPlugin('acl');

        // Local delivery is not available if the ACL plugin is not loaded.
        if (!$aclPlugin) {
            return;
        }

        $caldavNS = '{' . self::NS_CALDAV . '}';

        $principalUri = $aclPlugin->getPrincipalByUri($iTipMessage->recipient);
        if (!$principalUri) {
            $iTipMessage->scheduleStatus = '3.7;Could not find principal.';
            return;
        }

        // We found a principal URL, now we need to find its inbox.
        // Unfortunately we may not have sufficient privileges to find this, so
        // we are temporarily turning off ACL to let this come through.
        //
        // Once we support PHP 5.5, this should be wrapped in a try..finally
        // block so we can ensure that this privilege gets added again after.
        $this->server->removeListener('propFind', [$aclPlugin, 'propFind']);

        $result = $this->server->getProperties(
            $principalUri,
            [
                '{DAV:}principal-URL',
                 $caldavNS . 'calendar-home-set',
                 $caldavNS . 'schedule-inbox-URL',
                 $caldavNS . 'schedule-default-calendar-URL',
                '{http://sabredav.org/ns}email-address',
            ]
        );

        // Re-registering the ACL event
        $this->server->on('propFind', [$aclPlugin, 'propFind'], 20);

        if (!isset($result[$caldavNS . 'schedule-inbox-URL'])) {
            $iTipMessage->scheduleStatus = '5.2;Could not find local inbox';
            return;
        }
        if (!isset($result[$caldavNS . 'calendar-home-set'])) {
            $iTipMessage->scheduleStatus = '5.2;Could not locate a calendar-home-set';
            return;
        }
        if (!isset($result[$caldavNS . 'schedule-default-calendar-URL'])) {
            $iTipMessage->scheduleStatus = '5.2;Could not find a schedule-default-calendar-URL property';
            return;
        }

        $calendarPath = $result[$caldavNS . 'schedule-default-calendar-URL']->getHref();
        $homePath = $result[$caldavNS . 'calendar-home-set']->getHref();
        $inboxPath = $result[$caldavNS . 'schedule-inbox-URL']->getHref();

        if ($iTipMessage->method === 'REPLY') {
            $privilege = 'schedule-deliver-reply';
        } else {
            $privilege = 'schedule-deliver-invite';
        }

        if (!$aclPlugin->checkPrivileges($inboxPath, $caldavNS . $privilege, DAVACL\Plugin::R_PARENT, false)) {
            $iTipMessage->scheduleStatus = '3.8;insufficient privileges: ' . $privilege . ' is required on the recipient schedule inbox.';
            return;
        }

        // Next, we're going to find out if the item already exits in one of
        // the users' calendars.
        $uid = $iTipMessage->uid;

        $newFileName = 'sabredav-' . \Sabre\DAV\UUIDUtil::getUUID() . '.ics';

        $home = $this->server->tree->getNodeForPath($homePath);
        $inbox = $this->server->tree->getNodeForPath($inboxPath);

        $currentObject = null;
        $objectNode = null;
        $isNewNode = false;

        $result = $home->getCalendarObjectByUID($uid);
        if ($result) {
            // There was an existing object, we need to update probably.
            $objectPath = $homePath . '/' . $result;
            $objectNode = $this->server->tree->getNodeForPath($objectPath);
            $oldICalendarData = $objectNode->get();
            $currentObject = Reader::read($oldICalendarData);
        } else {
            $isNewNode = true;
        }

        $broker = new ITip\Broker();
        $newObject = $broker->processMessage($iTipMessage, $currentObject);

        $inbox->createFile($newFileName, $iTipMessage->message->serialize());

        if (!$newObject) {
            // We received an iTip message referring to a UID that we don't
            // have in any calendars yet, and processMessage did not give us a
            // calendarobject back.
            //
            // The implication is that processMessage did not understand the
            // iTip message.
            $iTipMessage->scheduleStatus = '5.0;iTip message was not processed by the server, likely because we didn\'t understand it.';
            return;
        }

        // Note that we are bypassing ACL on purpose by calling this directly.
        // We may need to look a bit deeper into this later. Supporting ACL
        // here would be nice.
        if ($isNewNode) {
            $calendar = $this->server->tree->getNodeForPath($calendarPath);
            $calendar->createFile($newFileName, $newObject->serialize());
        } else {
            // If the message was a reply, we may have to inform other
            // attendees of this attendees status. Therefore we're shooting off
            // another itipMessage.
            if ($iTipMessage->method === 'REPLY') {
                $this->processICalendarChange(
                    $oldICalendarData,
                    $newObject,
                    [$iTipMessage->recipient],
                    [$iTipMessage->sender]
                );
            }
            $objectNode->put($newObject->serialize(), FALSE);
        }
        $iTipMessage->scheduleStatus = '1.2;Message delivered locally';

    }

    /**
     * This method is triggered whenever a subsystem requests the privileges
     * that are supported on a particular node.
     *
     * We need to add a number of privileges for scheduling purposes.
     *
     * @param INode $node
     * @param array $supportedPrivilegeSet
     */
    function getSupportedPrivilegeSet(INode $node, array &$supportedPrivilegeSet) {

        $ns = '{' . self::NS_CALDAV . '}';
        if ($node instanceof IOutbox) {
            $supportedPrivilegeSet[$ns . 'schedule-send'] = [
                'abstract'   => false,
                'aggregates' => [
                    $ns . 'schedule-send-invite' => [
                        'abstract'   => false,
                        'aggregates' => [],
                    ],
                    $ns . 'schedule-send-reply' => [
                        'abstract'   => false,
                        'aggregates' => [],
                    ],
                    $ns . 'schedule-send-freebusy' => [
                        'abstract'   => false,
                        'aggregates' => [],
                    ],
                    // Privilege from an earlier scheduling draft, but still
                    // used by some clients.
                    $ns . 'schedule-post-vevent' => [
                        'abstract'   => false,
                        'aggregates' => [],
                    ],
                ]
            ];
        }
        if ($node instanceof IInbox) {
            $supportedPrivilegeSet[$ns . 'schedule-deliver'] = [
                'abstract'   => false,
                'aggregates' => [
                    $ns . 'schedule-deliver-invite' => [
                        'abstract'   => false,
                        'aggregates' => [],
                    ],
                    $ns . 'schedule-deliver-reply' => [
                        'abstract'   => false,
                        'aggregates' => [],
                    ],
                    $ns . 'schedule-query-freebusy' => [
                        'abstract'   => false,
                        'aggregates' => [],
                    ],
                ]
            ];
        }

    }

    /**
     * This method looks at an old iCalendar object, a new iCalendar object and
     * starts sending scheduling messages based on the changes.
     *
     * A list of addresses needs to be specified, so the system knows who made
     * the update, because the behavior may be different based on if it's an
     * attendee or an organizer.
     *
     * This method may update $newObject to add any status changes.
     *
     * @param VCalendar|string $oldObject
     * @param VCalendar $newObject
     * @param array $addresses
     * @param array $ignore Any addresses to not send messages to.
     * @param bool $modified A marker to indicate that the original object
     *   modified by this process.
     * @return void
     */
    protected function processICalendarChange($oldObject = null, VCalendar $newObject, array $addresses, array $ignore = [], &$modified = false) {

        $broker = new ITip\Broker();
        $messages = $broker->parseEvent($newObject, $addresses, $oldObject);

        if ($messages) $modified = true;

        foreach ($messages as $message) {

            if (in_array($message->recipient, $ignore)) {
                continue;
            }

            $this->deliver($message);

            if (isset($newObject->VEVENT->ORGANIZER) && ($newObject->VEVENT->ORGANIZER->getNormalizedValue() === $message->recipient)) {
                if ($message->scheduleStatus) {
                    $newObject->VEVENT->ORGANIZER['SCHEDULE-STATUS'] = $message->getScheduleStatus();
                }
                unset($newObject->VEVENT->ORGANIZER['SCHEDULE-FORCE-SEND']);

            } else {

                if (isset($newObject->VEVENT->ATTENDEE)) foreach ($newObject->VEVENT->ATTENDEE as $attendee) {

                    if ($attendee->getNormalizedValue() === $message->recipient) {
                        if ($message->scheduleStatus) {
                            $attendee['SCHEDULE-STATUS'] = $message->getScheduleStatus();
                        }
                        unset($attendee['SCHEDULE-FORCE-SEND']);
                        break;
                    }

                }

            }

        }

    }

    /**
     * Returns a list of addresses that are associated with a principal.
     *
     * @param string $principal
     * @return array
     */
    protected function getAddressesForPrincipal($principal) {

        $CUAS = '{' . self::NS_CALDAV . '}calendar-user-address-set';

        $properties = $this->server->getProperties(
            $principal,
            [$CUAS]
        );

        // If we can't find this information, we'll stop processing
        if (!isset($properties[$CUAS])) {
            return;
        }

        $addresses = $properties[$CUAS]->getHrefs();
        return $addresses;

    }

    /**
     * This method handles POST requests to the schedule-outbox.
     *
     * Currently, two types of requests are supported:
     *   * FREEBUSY requests from RFC 6638
     *   * Simple iTIP messages from draft-desruisseaux-caldav-sched-04
     *
     * The latter is from an expired early draft of the CalDAV scheduling
     * extensions, but iCal depends on a feature from that spec, so we
     * implement it.
     *
     * @param IOutbox $outboxNode
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return void
     */
    function outboxRequest(IOutbox $outboxNode, RequestInterface $request, ResponseInterface $response) {

        $outboxPath = $request->getPath();

        // Parsing the request body
        try {
            $vObject = VObject\Reader::read($request->getBody());
        } catch (VObject\ParseException $e) {
            throw new BadRequest('The request body must be a valid iCalendar object. Parse error: ' . $e->getMessage());
        }

        // The incoming iCalendar object must have a METHOD property, and a
        // component. The combination of both determines what type of request
        // this is.
        $componentType = null;
        foreach ($vObject->getComponents() as $component) {
            if ($component->name !== 'VTIMEZONE') {
                $componentType = $component->name;
                break;
            }
        }
        if (is_null($componentType)) {
            throw new BadRequest('We expected at least one VTODO, VJOURNAL, VFREEBUSY or VEVENT component');
        }

        // Validating the METHOD
        $method = strtoupper((string)$vObject->METHOD);
        if (!$method) {
            throw new BadRequest('A METHOD property must be specified in iTIP messages');
        }

        // So we support one type of request:
        //
        // REQUEST with a VFREEBUSY component

        $acl = $this->server->getPlugin('acl');

        if ($componentType === 'VFREEBUSY' && $method === 'REQUEST') {

            $acl && $acl->checkPrivileges($outboxPath, '{' . self::NS_CALDAV . '}schedule-send-freebusy');
            $this->handleFreeBusyRequest($outboxNode, $vObject, $request, $response);

            // Destroy circular references so PHP can GC the object.
            $vObject->destroy();
            unset($vObject);

        } else {

            throw new NotImplemented('We only support VFREEBUSY (REQUEST) on this endpoint');

        }

    }

    /**
     * This method is responsible for parsing a free-busy query request and
     * returning it's result.
     *
     * @param IOutbox $outbox
     * @param VObject\Component $vObject
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return string
     */
    protected function handleFreeBusyRequest(IOutbox $outbox, VObject\Component $vObject, RequestInterface $request, ResponseInterface $response) {

        $vFreeBusy = $vObject->VFREEBUSY;
        $organizer = $vFreeBusy->ORGANIZER;

        $organizer = (string)$organizer;

        // Validating if the organizer matches the owner of the inbox.
        $owner = $outbox->getOwner();

        $caldavNS = '{' . self::NS_CALDAV . '}';

        $uas = $caldavNS . 'calendar-user-address-set';
        $props = $this->server->getProperties($owner, [$uas]);

        if (empty($props[$uas]) || !in_array($organizer, $props[$uas]->getHrefs())) {
            throw new Forbidden('The organizer in the request did not match any of the addresses for the owner of this inbox');
        }

        if (!isset($vFreeBusy->ATTENDEE)) {
            throw new BadRequest('You must at least specify 1 attendee');
        }

        $attendees = [];
        foreach ($vFreeBusy->ATTENDEE as $attendee) {
            $attendees[] = (string)$attendee;
        }


        if (!isset($vFreeBusy->DTSTART) || !isset($vFreeBusy->DTEND)) {
            throw new BadRequest('DTSTART and DTEND must both be specified');
        }

        $startRange = $vFreeBusy->DTSTART->getDateTime();
        $endRange = $vFreeBusy->DTEND->getDateTime();

        $results = [];
        foreach ($attendees as $attendee) {
            $results[] = $this->getFreeBusyForEmail($attendee, $startRange, $endRange, $vObject);
        }

        $dom = new \DOMDocument('1.0', 'utf-8');
        $dom->formatOutput = true;
        $scheduleResponse = $dom->createElement('cal:schedule-response');
        foreach ($this->server->xml->namespaceMap as $namespace => $prefix) {

            $scheduleResponse->setAttribute('xmlns:' . $prefix, $namespace);

        }
        $dom->appendChild($scheduleResponse);

        foreach ($results as $result) {
            $xresponse = $dom->createElement('cal:response');

            $recipient = $dom->createElement('cal:recipient');
            $recipientHref = $dom->createElement('d:href');

            $recipientHref->appendChild($dom->createTextNode($result['href']));
            $recipient->appendChild($recipientHref);
            $xresponse->appendChild($recipient);

            $reqStatus = $dom->createElement('cal:request-status');
            $reqStatus->appendChild($dom->createTextNode($result['request-status']));
            $xresponse->appendChild($reqStatus);

            if (isset($result['calendar-data'])) {

                $calendardata = $dom->createElement('cal:calendar-data');
                $calendardata->appendChild($dom->createTextNode(str_replace("\r\n", "\n", $result['calendar-data']->serialize())));
                $xresponse->appendChild($calendardata);

            }
            $scheduleResponse->appendChild($xresponse);
        }

        $response->setStatus(200);
        $response->setHeader('Content-Type', 'application/xml');
        $response->setBody($dom->saveXML());

    }

    /**
     * Returns free-busy information for a specific address. The returned
     * data is an array containing the following properties:
     *
     * calendar-data : A VFREEBUSY VObject
     * request-status : an iTip status code.
     * href: The principal's email address, as requested
     *
     * The following request status codes may be returned:
     *   * 2.0;description
     *   * 3.7;description
     *
     * @param string $email address
     * @param DateTimeInterface $start
     * @param DateTimeInterface $end
     * @param VObject\Component $request
     * @return array
     */
    protected function getFreeBusyForEmail($email, \DateTimeInterface $start, \DateTimeInterface $end, VObject\Component $request) {

        $caldavNS = '{' . self::NS_CALDAV . '}';

        $aclPlugin = $this->server->getPlugin('acl');
        if (substr($email, 0, 7) === 'mailto:') $email = substr($email, 7);

        $result = $aclPlugin->principalSearch(
            ['{http://sabredav.org/ns}email-address' => $email],
            [
                '{DAV:}principal-URL',
                $caldavNS . 'calendar-home-set',
                $caldavNS . 'schedule-inbox-URL',
                '{http://sabredav.org/ns}email-address',

            ]
        );

        if (!count($result)) {
            return [
                'request-status' => '3.7;Could not find principal',
                'href'           => 'mailto:' . $email,
            ];
        }

        if (!isset($result[0][200][$caldavNS . 'calendar-home-set'])) {
            return [
                'request-status' => '3.7;No calendar-home-set property found',
                'href'           => 'mailto:' . $email,
            ];
        }
        if (!isset($result[0][200][$caldavNS . 'schedule-inbox-URL'])) {
            return [
                'request-status' => '3.7;No schedule-inbox-URL property found',
                'href'           => 'mailto:' . $email,
            ];
        }
        $homeSet = $result[0][200][$caldavNS . 'calendar-home-set']->getHref();
        $inboxUrl = $result[0][200][$caldavNS . 'schedule-inbox-URL']->getHref();

        // Do we have permission?
        $aclPlugin->checkPrivileges($inboxUrl, $caldavNS . 'schedule-query-freebusy');

        // Grabbing the calendar list
        $objects = [];
        $calendarTimeZone = new DateTimeZone('UTC');

        foreach ($this->server->tree->getNodeForPath($homeSet)->getChildren() as $node) {
            if (!$node instanceof ICalendar) {
                continue;
            }

            $sct = $caldavNS . 'schedule-calendar-transp';
            $ctz = $caldavNS . 'calendar-timezone';
            $props = $node->getProperties([$sct, $ctz]);

            if (isset($props[$sct]) && $props[$sct]->getValue() == ScheduleCalendarTransp::TRANSPARENT) {
                // If a calendar is marked as 'transparent', it means we must
                // ignore it for free-busy purposes.
                continue;
            }

            if (isset($props[$ctz])) {
                $vtimezoneObj = VObject\Reader::read($props[$ctz]);
                $calendarTimeZone = $vtimezoneObj->VTIMEZONE->getTimeZone();

                // Destroy circular references so PHP can garbage collect the object.
                $vtimezoneObj->destroy();

            }

            // Getting the list of object uris within the time-range
            $urls = $node->calendarQuery([
                'name'         => 'VCALENDAR',
                'comp-filters' => [
                    [
                        'name'           => 'VEVENT',
                        'comp-filters'   => [],
                        'prop-filters'   => [],
                        'is-not-defined' => false,
                        'time-range'     => [
                            'start' => $start,
                            'end'   => $end,
                        ],
                    ],
                ],
                'prop-filters'   => [],
                'is-not-defined' => false,
                'time-range'     => null,
            ]);

            $calObjects = array_map(function($url) use ($node) {
                $obj = $node->getChild($url)->get();
                return $obj;
            }, $urls);

            $objects = array_merge($objects, $calObjects);

        }

        $inboxProps = $this->server->getProperties(
            $inboxUrl,
            $caldavNS . 'calendar-availability'
        );

        $vcalendar = new VObject\Component\VCalendar();
        $vcalendar->METHOD = 'REPLY';

        $generator = new VObject\FreeBusyGenerator();
        $generator->setObjects($objects);
        $generator->setTimeRange($start, $end);
        $generator->setBaseObject($vcalendar);
        $generator->setTimeZone($calendarTimeZone);

        if ($inboxProps) {
            $generator->setVAvailability(
                VObject\Reader::read(
                    $inboxProps[$caldavNS . 'calendar-availability']
                )
            );
        }

        $result = $generator->getResult();

        $vcalendar->VFREEBUSY->ATTENDEE = 'mailto:' . $email;
        $vcalendar->VFREEBUSY->UID = (string)$request->VFREEBUSY->UID;
        $vcalendar->VFREEBUSY->ORGANIZER = clone $request->VFREEBUSY->ORGANIZER;

        return [
            'calendar-data'  => $result,
            'request-status' => '2.0;Success',
            'href'           => 'mailto:' . $email,
        ];
    }

    /**
     * This method checks the 'Schedule-Reply' header
     * and returns false if it's 'F', otherwise true.
     *
     * @param RequestInterface $request
     * @return bool
     */
    private function scheduleReply(RequestInterface $request) {

        $scheduleReply = $request->getHeader('Schedule-Reply');
        return $scheduleReply !== 'F';

    }

    /**
     * Returns a bunch of meta-data about the plugin.
     *
     * Providing this information is optional, and is mainly displayed by the
     * Browser plugin.
     *
     * The description key in the returned array may contain html and will not
     * be sanitized.
     *
     * @return array
     */
    function getPluginInfo() {

        return [
            'name'        => $this->getPluginName(),
            'description' => 'Adds calendar-auto-schedule, as defined in rf6868',
            'link'        => 'http://sabre.io/dav/scheduling/',
        ];

    }
}
