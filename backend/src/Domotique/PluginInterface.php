<?php

namespace App\Domotique;

/**
 * A configured plugin (Connector) attached to a property. In PMS only the Lodgify plugin remains (bookings); physical
 * connectors live in Rocket Place. See App\Entity\Connector.
 */
interface PluginInterface
{
    public function id(): string;

    public function name(): string;

    public function description(): string;

    public function icon(): string;

    /** 'domotique' or 'general': grouping of the catalogue and of the "Domotique" tab. */
    public function category(): string;

    /** @return list<array{key: string, label: string, type: string, required?: bool, secret?: bool, help?: string, placeholder?: string, options?: list<array{label: string, value: string}>}> */
    public function fields(): array;

    /** Server-side check of the config sent by the browser (called on save); may adjust/normalise values. @param array<string, string> $config @return array<string, string> */
    public function validate(array $config, string $propertyId, ?string $connectorId): array;

    /** Quick connectivity check, shown after "Tester". @param array<string, string> $config */
    public function test(array $config): string;

    /** Info cards for the "Domotique" tab. @param array<string, string> $config @return list<array{title: string, icon?: string, items: list<array{label: string, value: string}>}> */
    public function info(array $config): array;

    /**
     * Capabilities this plugin provides beyond the info cards, e.g. 'bookings' (App\Lodgify\BookingCapablePluginInterface).
     *
     * @return list<string>
     */
    public function capabilities(): array;
}
