<?php

$capsule = new \Illuminate\Database\Capsule\Manager;
$capsule->addConnection($settings['db']);
$capsule->bootEloquent();
$capsule->setAsGlobal();
