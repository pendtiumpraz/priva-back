<?php

namespace App\Services\Crm;

use App\Models\CrmCredential;
use InvalidArgumentException;

class CrmConnectorFactory
{
    /**
     * Resolve the connector for a given credential's provider.
     */
    public static function make(CrmCredential $credential): CrmConnectorContract
    {
        return match ($credential->provider) {
            CrmCredential::PROVIDER_HUBSPOT => new HubspotConnector(),
            CrmCredential::PROVIDER_MAILCHIMP => new MailchimpConnector(),
            CrmCredential::PROVIDER_SALESFORCE => new SalesforceConnector(),
            CrmCredential::PROVIDER_WEBHOOK => new WebhookConnector(),
            default => throw new InvalidArgumentException("Unknown CRM provider: {$credential->provider}"),
        };
    }
}
