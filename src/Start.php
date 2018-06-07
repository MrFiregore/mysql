<?php
    define("__ROOT__", substr(__DIR__, 0, strpos(__DIR__, "vendor") ?: strpos(__DIR__, "src")));
    if (!file_exists(__ROOT__ . ".env")) {
        file_put_contents(__ROOT__ . ".env", "host=localhost\nuser=root\npassword=\ndatabase=\nport=3306");
    }
    $dotenv = new Dotenv\Dotenv(__ROOT__);
    $dotenv->load();
    foreach ($_ENV as $key => $value) {
        if (strtolower($value) === "true") $_ENV[$key] = true;
        if (strtolower($value) === "false") $_ENV[$key] = false;
        if (ctype_digit($value)) $_ENV[$key] = intval($value);
    }
