<?php

require "vendor/autoload.php";

use pocketmine\utils\Terminal;
use pocketmine\utils\Timezone;
use pocketmine\entity\Attribute;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\item\ItemFactory;
use proxy\ProxyServer;

Terminal::init(true);
Timezone::init();
Attribute::init();
PacketPool::init();
ItemFactory::init();

$proxyServer = new ProxyServer();
$proxyServer->getLogger()->info("Starting proxy server on locale...");
$proxyServer->start();