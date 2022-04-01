<?php

use League\Flysystem\Filesystem;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\Ftp\UnableToConnectToFtpHost;

function deleteDirectory($dir) {
    if (!file_exists($dir)) {
        return true;
    }

    if (!is_dir($dir)) {
        return unlink($dir);
    }

    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }

        if (!deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }

    }

    return rmdir($dir);
}

function retryStream(string $path, Filesystem $filesystem, int $maxretries = 3) {
    $stream = null;

    for($i = 1; $i <= $maxretries; $i++) {
        try {
            $stream = $filesystem->readStream($path);
            break;
        } catch(UnableToReadFile | UnableToConnectToFtpHost $e) {
            $stream = $e;
            sleep(2);
        }
    }

    if($stream instanceof UnableToReadFile) {
        throw new UnableToReadFile($stream);
    }

    if($stream instanceof UnableToConnectToFtpHost) {
        throw new UnableToConnectToFtpHost($stream);
    }

    return $stream;
}

function checkFile(string $path) {
    $extension = pathinfo($path, PATHINFO_EXTENSION);
    return $extension ? true : false;
}

function shouldExclude($configExclude = [], $path) {
    foreach($configExclude as $config) {
        if(strpos($path, $config) !== false) {
            return true;
        }
    }

    return false;
}

if (! function_exists('env')) {
    /**
     * Gets the value of an environment variable. Supports boolean, empty and null.
     *
     * @param  string  $key
     * @param  mixed   $default
     * @return mixed
     */
    function env($key, $default = null)
    {
        $value = $_ENV[$key] ?? false;

        if ($value === false) {
            return value($default);
        }

        switch (strtolower($value)) {
            case 'true':
            case '(true)':
                return true;

            case 'false':
            case '(false)':
                return false;

            case 'empty':
            case '(empty)':
                return '';

            case 'null':
            case '(null)':
                return;
        }

        if (strlen($value) > 1 && starts_with($value, '"') && ends_with($value, '"')) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}

if (! function_exists('value')) {
    /**
     * Return the default value of the given value.
     *
     * @param  mixed  $value
     * @return mixed
     */
    function value($value)
    {
        return $value instanceof Closure ? $value() : $value;
    }
}

if (! function_exists('starts_with')) {
    /**
     * Determine if a given string starts with a given substring.
     *
     * @param  string  $haystack
     * @param  string|array  $needles
     * @return bool
     */
    function starts_with($haystack, $needles)
    {
        foreach ((array) $needles as $needle) {
            if ($needle != '' && substr($haystack, 0, strlen($needle)) === (string) $needle) {
                return true;
            }
        }

        return false;
    }
}

if (! function_exists('ends_with')) {
    /**
     * Determine if a given string ends with a given substring.
     *
     * @param  string  $haystack
     * @param  string|array  $needles
     * @return bool
     */
    function ends_with($haystack, $needles)
    {
        foreach ((array) $needles as $needle) {
            if (substr($haystack, -strlen($needle)) === (string) $needle) {
                return true;
            }
        }

        return false;
    }
}