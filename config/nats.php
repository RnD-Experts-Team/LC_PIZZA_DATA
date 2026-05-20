<?php

$devMode = (int) env('DEV_MODE', 0) === 1;

$authSubject = $devMode
    ? 'auth.testing.v1.>'
    : 'auth.v1.>';

$dataSubject = $devMode
    ? 'data.testing.v1.>'
    : 'data.v1.>';

$hiringSubject = $devMode
    ? 'hiring.testing.v1.>'
    : 'hiring.v1.>';
$notificationsSubject = $devMode
? 'notifications.testing.v1.>'
: 'notifications.v1.>';
return [
    'dev_mode' => $devMode,
    'host' => env('NATS_HOST', '127.0.0.1'),
    'port' => (int) env('NATS_PORT', 4222),

    'user' => env('NATS_USER'),
    'pass' => env('NATS_PASS'),
    'token' => env('NATS_TOKEN'),

    'jetstream' => [
        'stream' => $devMode
            ? env('NATS_NOTIFICATIONS_STREAM', 'NOTIFICATIONS_TESTING_EVENTS')
            : env('NATS_NOTIFICATIONS_STREAM', 'NOTIFICATIONS_EVENTS'),

        'subjects' => [
            $notificationsSubject,
        ],
    ],


    'publishers' => [
        [
            'name' => $devMode
                ? env('NATS_DATA_STREAM', 'DATA_TESTING_EVENTS')
                : env('NATS_DATA_STREAM', 'DATA_EVENTS'),
            'subjects' => [$dataSubject],
        ],
        [
        'name' => $devMode
            ? env('NATS_NOTIFICATIONS_STREAM', 'NOTIFICATIONS_TESTING_EVENTS')
            : env('NATS_NOTIFICATIONS_STREAM', 'NOTIFICATIONS_EVENTS'),
        'subjects' => [$notificationsSubject],
         ],
    ],
    /**
     * Add streams here as new projects appear.
     * Each stream gets its own durable pull consumer.
     */
    'streams' => [
        [
            'name' => $devMode ? env('NATS_AUTH_STREAM', 'AUTH_TESTING_EVENTS') : env('NATS_AUTH_STREAM', 'AUTH_EVENTS'),
            'durable' => $devMode ? env('NATS_AUTH_DURABLE', 'DATA_AUTH_TESTING_CONSUMER') : env('NATS_AUTH_DURABLE', 'DATA_AUTH_CONSUMER'),
            'filter_subject' => $authSubject, // match your stream subjects
        ],
        [
            'name' => $devMode ? env('NATS_HIRING_STREAM', 'HIRING_TESTING_EVENTS') : env('NATS_HIRING_STREAM', 'HIRING_EVENTS'),
            'durable' => $devMode ? env('NATS_HIRING_DURABLE', 'DATA_HIRING_TESTING_CONSUMER') : env('NATS_HIRING_DURABLE', 'DATA_HIRING_CONSUMER'),
            'filter_subject' => $hiringSubject, // match your stream subjects
        ],
        // Example additional stream later:
        // [
        //   'name' => env('NATS_PROJECT_STREAM', 'PROJECT_EVENTS'),
        //   'durable' => env('NATS_PROJECT_DURABLE', 'QA_PROJECT_CONSUMER'),
        //   'filter_subject' => 'project.v1.>',
        // ],
    ],

    'pull' => [
        'batch' => (int) env('NATS_PULL_BATCH', 25),
        'timeout_ms' => (int) env('NATS_PULL_TIMEOUT_MS', 2000),
        'sleep_ms' => (int) env('NATS_PULL_SLEEP_MS', 250),
    ],
];
