<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CrmService
{
    /**
     * Push Consent Log to Connected CRM
     */
    public static function pushConsent(string $provider, array $config, \App\Models\ConsentLog $log)
    {
        try {
            switch ($provider) {
                case 'odoo':
                    self::pushToOdoo($config, $log);
                    break;
                case 'salesforce':
                    self::pushToSalesforce($config, $log);
                    break;
                case 'hubspot':
                    self::pushToHubspot($config, $log);
                    break;
                default:
                    // Simulated success for other generic CRMs
                    Log::info("Simulated CRM push to {$provider} for user {$log->user_identifier}");
                    break;
            }
        } catch (\Throwable $e) {
            Log::error("Failed CRM push to {$provider}: " . $e->getMessage());
        }
    }

    private static function pushToOdoo(array $config, \App\Models\ConsentLog $log)
    {
        $url = rtrim($config['odoo_url'] ?? '', '/');
        $db = $config['db_name'] ?? '';
        $user = $config['username'] ?? '';
        $pass = $config['password'] ?? '';

        if (!$url || !$db || !$user || !$pass) return;

        // Odoo requires JSON-RPC for easy web integration if XML-RPC isn't used
        // Since standard PHP might lack xmlrpc, we use JSON-RPC 2.0 to /jsonrpc
        
        // 1. Authenticate (Get UID)
        $authResponse = Http::post("{$url}/jsonrpc", [
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => [
                'service' => 'common',
                'method' => 'login',
                'args' => [$db, $user, $pass]
            ],
            'id' => rand()
        ]);

        $uid = $authResponse->json('result');
        if (!$uid) {
            throw new \Exception("Odoo authentication failed");
        }

        // 2. Search for the Contact (res.partner)
        $searchResponse = Http::post("{$url}/jsonrpc", [
            'jsonrpc' => '2.0',
            'method' => 'call',
            'params' => [
                'service' => 'object',
                'method' => 'execute_kw',
                'args' => [
                    $db, $uid, $pass, 'res.partner', 'search',
                    [[['email', '=', $log->user_identifier]]]
                ]
            ],
            'id' => rand()
        ]);

        $partnerIds = $searchResponse->json('result');
        
        $consentString = "Privasimu Consent v{$log->policy_version}: \n";
        foreach ($log->consented_items as $item => $val) {
            $consentString .= "- {$item}: " . ($val ? 'Yes' : 'No') . "\n";
        }

        if (!empty($partnerIds)) {
            // Update existing contact (Append to comment/Internal note, or mapped consent field)
            $partnerId = $partnerIds[0];
            Http::post("{$url}/jsonrpc", [
                'jsonrpc' => '2.0',
                'method' => 'call',
                'params' => [
                    'service' => 'object',
                    'method' => 'execute_kw',
                    'args' => [
                        $db, $uid, $pass, 'res.partner', 'write',
                        [[$partnerId], ['comment' => $consentString]]
                    ]
                ],
                'id' => rand()
            ]);
            Log::info("Updated Odoo Contact {$partnerId} with Consent");
        } else {
            // Create New Lead or Contact with consent
            Http::post("{$url}/jsonrpc", [
                'jsonrpc' => '2.0',
                'method' => 'call',
                'params' => [
                    'service' => 'object',
                    'method' => 'execute_kw',
                    'args' => [
                        $db, $uid, $pass, 'res.partner', 'create',
                        [['name' => $log->user_identifier, 'email' => $log->user_identifier, 'comment' => $consentString]]
                    ]
                ],
                'id' => rand()
            ]);
            Log::info("Created new Odoo Contact for {$log->user_identifier} with Consent");
        }
    }

    private static function pushToSalesforce(array $config, \App\Models\ConsentLog $log)
    {
        // ... omitted actual OAuth implementation for brevity ...
        Log::info("Salesforce integration called for {$log->user_identifier} (Simulated)");
    }

    private static function pushToHubspot(array $config, \App\Models\ConsentLog $log)
    {
        // ... omitted actual API implementation for brevity ...
        Log::info("HubSpot integration called for {$log->user_identifier} (Simulated)");
    }
}
