<?php

namespace proxy;

use Exception;
use GlobalLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use OpenSSLAsymmetricKey;

class XboxLiveHandler
{
    private Client $client;

    public function __construct()
    {
        // Source:
        // https://github.com/PrismarineJS/prismarine-auth/blob/master/src/MicrosoftAuthFlow.js#L73
        $this->client = new Client();
    }

    /**
     * @throws Exception
     */
    public function getAuthDeviceCode(/* $callback */): mixed
    {
        $acquireTime = time();
        $codeRequest = [
            "scope" => ["service::user.auth.xboxlive.com::MBI_SSL"],
            "client_id" => "00000000441cc96b",
            "response_type" => "device_code"
        ];

        try {
            $response = $this->client->post('https://login.live.com/oauth20_connect.srf', [
                'form_params' => $codeRequest,
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded'
                ]
            ]);

            $responseData = json_decode($response->getBody(), true);
            $expireTime = $acquireTime + ($responseData['expires_in'] * 1000) - 100;

            $cookies = [];
            foreach ($response->getHeader('Set-Cookie') as $cookie) {
                $cookies[] = explode(';', $cookie)[0];
            }

            $pooling = true;
            while ($pooling && $expireTime > time()) {
                sleep($responseData['interval']);

                $ver = [
                    'client_id' => '00000000441cc96b',
                    'device_code' => $responseData['device_code'],
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code'
                ];

                $tokenResponse = $this->client->post('https://login.live.com/oauth20_token.srf', [
                    'form_params' => $ver,
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                        'Cookie' => implode('; ', $cookies)
                    ]
                ]);

                $tokenData = json_decode($tokenResponse->getBody(), true);

                if (isset($tokenData['error'])) {
                    if ($tokenData['error'] === 'authorization_pending') {
                        GlobalLogger::get()->error('Still waiting for authorization');
                    } else {
                        throw new Exception('Failed to acquire authorization code from device token');
                    }
                } else {
                    $pooling = false;
                    return $tokenData['access_token'];
                }
            }
        } catch (RequestException $e) {
            echo "HTTP Request failed\n";
            echo $e->getMessage();
        } catch (GuzzleException $e) {
            echo "HTTP Request failed\n";
            echo $e->getMessage();
        }
        throw new Exception('Authentication failed, timed out');
    }

    public function getMinecraftBedrockToken(OpenSSLAsymmetricKey $publicKey, $callback)
    {
        $callback();  // TODO: get result
    }
}