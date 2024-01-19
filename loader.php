<?php

require "vendor/autoload.php";

use proxy\ProxyServer;

//Terminal::init(true);
//Timezone::init();
//Attribute::init();
//ItemFactory::init();

$proxyServer = new ProxyServer();
$proxyServer->getLogger()->info("Starting proxy server on locale...");
$proxyServer->start();