<?php
spl_autoload_register(function($classname) {
    $namespace = substr($classname, 0, strrpos($classname, "\\"));
    $class = substr($classname, strrpos($classname, "\\")+1);

    $path = __DIR__.'/';

    foreach(explode("\\", $namespace) as $item) {
        $path .= "$item/";
    }
    $path .= "$class.php";

    require_once $path;
});