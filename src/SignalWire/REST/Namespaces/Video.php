<?php

declare(strict_types=1);

namespace SignalWire\REST\Namespaces;

use SignalWire\REST\CrudResource;
use SignalWire\REST\HttpClient;

/**
 * Video API namespace.
 *
 * Mirrors Python ``signalwire.rest.namespaces.video.VideoNamespace``: groups
 * the Video API sub-resources (rooms, room_sessions, room_recordings,
 * conferences, conference_tokens, streams) under one object.
 */
class Video
{
    private HttpClient $http;

    private VideoRooms $rooms;
    private VideoRoomTokens $roomTokens;
    private VideoRoomSessions $roomSessions;
    private VideoRoomRecordings $roomRecordings;
    private VideoConferences $conferences;
    private VideoConferenceTokens $conferenceTokens;
    private VideoStreams $streams;

    public function __construct(HttpClient $http)
    {
        $this->http = $http;
        $base = '/api/video';
        $this->rooms = new VideoRooms($http, $base . '/rooms');
        $this->roomTokens = new VideoRoomTokens($http, $base . '/room_tokens');
        $this->roomSessions = new VideoRoomSessions($http, $base . '/room_sessions');
        $this->roomRecordings = new VideoRoomRecordings($http, $base . '/room_recordings');
        $this->conferences = new VideoConferences($http, $base . '/conferences');
        $this->conferenceTokens = new VideoConferenceTokens($http, $base . '/conference_tokens');
        $this->streams = new VideoStreams($http, $base . '/streams');
    }

    public function getHttp(): HttpClient
    {
        return $this->http;
    }

    public function rooms(): VideoRooms
    {
        return $this->rooms;
    }

    public function roomTokens(): VideoRoomTokens
    {
        return $this->roomTokens;
    }

    public function roomSessions(): VideoRoomSessions
    {
        return $this->roomSessions;
    }

    public function roomRecordings(): VideoRoomRecordings
    {
        return $this->roomRecordings;
    }

    public function conferences(): VideoConferences
    {
        return $this->conferences;
    }

    public function conferenceTokens(): VideoConferenceTokens
    {
        return $this->conferenceTokens;
    }

    public function streams(): VideoStreams
    {
        return $this->streams;
    }
}
