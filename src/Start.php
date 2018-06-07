<?php
    define("__ROOT__", substr(__DIR__, 0, strpos(__DIR__, "vendor") ?: strpos(__DIR__, "src")));
    $dotenv = new Dotenv\Dotenv(__ROOT__);
    $dotenv->load();
    foreach ($_ENV as $key => $value) {
        if (strtolower($value) === "true") $_ENV[$key] = true;
        if (strtolower($value) === "false") $_ENV[$key] = false;
        if (ctype_digit($value)) $_ENV[$key] = intval($value);
    }
