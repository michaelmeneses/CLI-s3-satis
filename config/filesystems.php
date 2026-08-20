<?php

return [
    'default' => 'local',
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => getcwd(),
        ],

        'temp' => [
            'driver' => 'local',
            'root' => str(sys_get_temp_dir())->finish(DIRECTORY_SEPARATOR)->append('s3-satis-generator')->finish(DIRECTORY_SEPARATOR),
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('S3_ACCESS_KEY_ID'),
            'secret' => env('S3_SECRET_ACCESS_KEY'),
            'region' => env('S3_REGION', 'us-east-1'),
            'bucket' => env('S3_BUCKET'),
            'endpoint' => env('S3_ENDPOINT'),
            'use_path_style_endpoint' => env('S3_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            // Passed straight through to the AWS SDK's S3Client constructor
            // (Laravel's S3 driver forwards the whole disk config array
            // unchanged). Without these, a stalled connection to S3/R2 has no
            // upper bound and downloadFromS3()/uploadToS3() can hang
            // indefinitely instead of failing fast and retrying.
            'http' => [
                'connect_timeout' => (float) env('S3_CONNECT_TIMEOUT', 10),
                'timeout' => (float) env('S3_REQUEST_TIMEOUT', 30),
            ],
            'retries' => (int) env('S3_RETRIES', 5),
        ],
    ],
];
