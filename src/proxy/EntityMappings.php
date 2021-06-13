<?php


namespace proxy;


class EntityMappings
{
    // Entity runtime IDs mappings
    private static array $realToProxyRuntimeIds = [];
    private static array $proxyToRealRuntimeIds = [];
    private static array $nameToRuntimeIDs = [];

    // TODO everything
    public static function mapEntityRuntimeID(int $realID, string $username): void {
        $allocatedID = count(self::$nameToRuntimeIDs) + 1;
        self::$realToProxyRuntimeIds[$realID] = $allocatedID;
        self::$proxyToRealRuntimeIds[$allocatedID] = $realID;
        self::$nameToRuntimeIDs[$username] = $allocatedID;
    }

    public static function getRealRuntimeID(int $proxyRuntimeID): int {
        return self::$proxyToRealRuntimeIds[$proxyRuntimeID];
    }

    public static function getProxyRuntimeID(int $realRuntimeID): int {
        return self::$realToProxyRuntimeIds[$realRuntimeID];
    }

    public static function clear(): void {
        self::$realToProxyRuntimeIds = [];
        self::$proxyToRealRuntimeIds = [];
        self::$nameToRuntimeIDs = [];
    }
}