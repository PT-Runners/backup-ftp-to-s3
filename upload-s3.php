<?php

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use Dotenv\Dotenv;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;
use League\Flysystem\UnableToReadFile;

require 'vendor/autoload.php';

$ftpServers = require 'config/ftp.php';

$now = date('Y-m-d-His');

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

if(env('SENTRY_DSN'))
{
    \Sentry\init(['dsn' => env('SENTRY_DSN')]);
}

$adapters = (object)[
    "local" => new LocalFilesystemAdapter(
        // Determine root directory
        __DIR__.'/storage/'
    ),
    "s3" => new AwsS3V3Adapter(
        new S3Client([
            'endpoint' => env('S3_ENDPOINT'),
            'region' => env('S3_REGION'),
            'version' => env('S3_VERSION'),
            'credentials' => [
                'key'    => env('S3_KEY'),
                'secret' => env('S3_SECRET')
            ]
        ]), 
        env('S3_BUCKET')
    )
];

$filesystems = (object)[
    "local" => new Filesystem($adapters->local),
    "s3" => new Filesystem($adapters->s3),
    "ftps" => []
];

foreach($ftpServers as $ftpServer) {

    $adapter = new FtpAdapter(
        // Connection options
        FtpConnectionOptions::fromArray([
            'host' => $ftpServer["host"], // required
            'root' => $ftpServer["root"], // required
            'username' => $ftpServer["username"], // required
            'password' => $ftpServer["password"], // required
            'port' => $ftpServer["port"],
            'ssl' => false,
            'timeout' => 60,
            'utf8' => false,
            'passive' => true,
            'transferMode' => FTP_BINARY,
            'systemType' => null, // 'windows' or 'unix'
            'ignorePassiveAddress' => null, // true or false
            'timestampsOnUnixListingsEnabled' => false, // true or false
            'recurseManually' => true // true 
        ])
    );

    $filesystems->ftps[] = new Filesystem($adapter);
}

foreach($ftpServers as $key => $ftpServer) {

    $ftpServerName = $ftpServer["name"];

    $pathsInclude = $ftpServer["include"] ?? [];

    $pathsExclude = $ftpServer["exclude"] ?? [];

    foreach($pathsInclude as $pathInclude) {

        if(shouldExclude($pathsExclude, $pathInclude)) {
            continue;
        }

        if(checkFile($pathInclude))
        {
            if(!$filesystems->ftps[$key]->fileExists($pathInclude)) {
                continue;
            }

            $stream = retryStream($pathInclude, $filesystems->ftps[$key]);

            $filesystems->local->writeStream("$ftpServerName/$pathInclude", $stream);

            dump("$ftpServerName | File: $pathInclude");

            continue;

        } else {

            $listing = $filesystems->ftps[$key]->listContents($pathInclude, true);

            foreach ($listing as $item) {

                $path = $item->path();

                if(shouldExclude($pathsExclude, $path)) {
                    continue;
                }

                if ($item instanceof \League\Flysystem\FileAttributes) {
                    
                    $stream = retryStream($path, $filesystems->ftps[$key]);

                    $filesystems->local->writeStream("$ftpServerName/$path", $stream);

                    dump("$ftpServerName | File: $path");

                }
            }

        }

    }
}

foreach($ftpServers as $key => $ftpServer) {

    $pathStorage = __DIR__. "/storage";

    $ftpServerName = $ftpServer["name"];

    $rootPath = realpath("$pathStorage/$ftpServerName");

    // Initialize archive object
    $zip = new ZipArchive();
    $zip->open("$pathStorage/$ftpServerName-$now.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE);

    // Create recursive directory iterator
    /** @var SplFileInfo[] $files */
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootPath),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($files as $name => $file)
    {
        // Skip directories (they would be added automatically)
        if (!$file->isDir())
        {
            // Get real and relative path for current file
            $filePath = $file->getRealPath();
            $relativePath = substr($filePath, strlen($rootPath) + 1);

            // Add current file to archive
            $zip->addFile($filePath, $relativePath);
        }
    }

    $zip->close();

    $stream = $filesystems->local->readStream("$ftpServerName-$now.zip");
    $filesystems->s3->writeStream("$ftpServerName-$now.zip", $stream);

    unlink("$pathStorage/$ftpServerName-$now.zip");

    if (is_dir("$pathStorage/$ftpServerName")) {
        deleteDirectory("$pathStorage/$ftpServerName");
    }
}