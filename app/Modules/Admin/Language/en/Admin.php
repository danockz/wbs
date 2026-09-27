<?php

declare(strict_types=1);

/**
 * Admin module UI strings. English is the guaranteed fallback: every key here
 * MUST exist so a missing translation degrades to English rather than a raw key.
 * The effective-config page is SELF-CONTAINED (own <html>) so it includes
 * _locale.php for a locale-aware <html lang dir> (RTL for Arabic). {0}
 * placeholders are interpolated via $li().
 *
 * `decision` and `inheritance_mode` are FIXED vocabularies, localized with a
 * raw-value fallback. Capability code, group ids, and the resolved value are
 * server/config data shown verbatim.
 */

return [
    'metaTitle'    => 'Effective group config — Administration',
    'heading'      => 'Effective configuration',
    'sub'          => 'How a capability resolves for a group, and where the value comes from.',
    'capabilityLbl' => 'Capability',
    'groupLbl'     => 'Group',
    'valueLbl'     => 'Effective value',
    'sourceLbl'    => 'Source group',
    'versionLbl'   => 'Version',
    'decisionLbl'  => 'Decision',
    'inheritanceLbl' => 'Inheritance mode',
    'noValue'      => 'No effective value',
    'nullSource'   => '— none —',
    'boolTrue'     => 'Enabled',
    'boolFalse'    => 'Disabled',

    // Resolution decision — fixed vocabulary, raw-value fallback.
    'decision' => [
        'own_child_owned'         => 'Set on this group (child-owned)',
        'own_not_inheritable'     => 'Set on this group (not inheritable)',
        'child_override'          => 'Overridden on this group',
        'inherited_from_ancestor' => 'Inherited from an ancestor',
        'no_effective_value'      => 'No effective value',
    ],

    // Inheritance mode — fixed vocabulary, raw-value fallback.
    'inheritance' => [
        'ancestor_default_child_override' => 'Ancestor default, child may override',
        'inherit_only'                    => 'Inherit only',
        'child_owned'                     => 'Child-owned',
        'not_inheritable'                 => 'Not inheritable',
    ],

    // Organization settings page (GET /admin).
    'settings' => [
        'metaTitle'    => 'Organization settings',
        'heading'      => 'Organization settings',
        'sub'          => 'Platform configuration and feature flags for your organization.',
        'settingsH'    => 'Settings',
        'settingsCount'=> '{0} settings',
        'settingsOne'  => '{0} setting',
        'settingsEmpty'=> 'No settings configured.',
        'flagsH'       => 'Feature flags',
        'flagsCount'   => '{0} flags',
        'flagsOne'     => '{0} flag',
        'flagsEmpty'   => 'No feature flags configured.',
        'colKey'       => 'Key',
        'colValue'     => 'Value',
        'colVersion'   => 'Version',
        'colUpdated'   => 'Updated',
        'colFlag'      => 'Flag',
        'colScope'     => 'Scope',
        'colState'     => 'State',
        'scopeOrg'     => 'Organization-wide',
        'on'           => 'On',
        'off'          => 'Off',
        'noValue'      => '—',

        // Write controls (create/edit forms + inline toggles).
        'form' => [
            'newSetting'       => 'New setting',
            'newFlag'          => 'New feature flag',
            'keyLabel'         => 'Key',
            'keyPh'            => 'e.g. branding.primary_color',
            'flagKeyLabel'     => 'Flag key',
            'flagKeyPh'        => 'e.g. beta.new_dashboard',
            'typeLabel'        => 'Type',
            'valueLabel'       => 'Value',
            'valuePh'          => 'value',
            'scopeLabel'       => 'Scope',
            'scopeOrgOption'   => 'Organization-wide',
            'enabledLabel'     => 'Enabled',
            'descriptionLabel' => 'Description',
            'newHint'          => 'Values are stored with their type; JSON accepts objects and arrays.',
            'saveNew'          => 'Save',
            'saveEdit'         => 'Save',
            'edit'             => 'Edit',
            'editCol'          => 'Edit',
            'turnOn'           => 'Turn on',
            'turnOff'          => 'Turn off',
            'savedFlash'       => 'Saved.',
            'type' => [
                'string'  => 'Text',
                'integer' => 'Integer',
                'number'  => 'Number',
                'boolean' => 'Boolean',
                'json'    => 'JSON',
            ],
        ],
    ],

    // Group-config write form (on the effective-config resolution page).
    'setForm' => [
        'heading'    => 'Set this group’s value',
        'sub'        => 'Override the capability for this group. Inheritance mode controls whether descendants may override.',
        'typeLabel'  => 'Type',
        'valueLabel' => 'Value',
        'valuePh'    => 'value',
        'modeLabel'  => 'Inheritance mode',
        'save'       => 'Save value',
        'type' => [
            'string'  => 'Text',
            'integer' => 'Integer',
            'number'  => 'Number',
            'boolean' => 'Boolean',
            'json'    => 'JSON',
        ],
    ],

    // Write-path validation messages (service-key fallbacks resolved via lang()).
    'setting_key_required'        => 'A setting key is required.',
    'flag_key_required'           => 'A flag key is required.',
    'group_config_input_required' => 'A group and capability are required.',

    // Providers page (GET /admin/providers).
    'providers' => [
        'metaTitle'   => 'Providers',
        'heading'     => 'Provider connections',
        'sub'         => 'Configured integration providers and where they are in the approval lifecycle.',
        'count'       => '{0} providers',
        'countOne'    => '{0} provider',
        'empty'       => 'No provider connections configured.',
        'colName'     => 'Connection',
        'colCategory' => 'Category',
        'colStatus'   => 'Status',
        'colTested'   => 'Last tested',
        'adapter'     => 'Adapter',
        'noName'      => 'Unnamed connection',
        'never'       => 'Never',
        'status' => [
            'draft'            => 'Draft',
            'tested'           => 'Tested',
            'pending_approval' => 'Pending approval',
            'active'           => 'Active',
            'disabled'         => 'Disabled',
            'revoked'          => 'Revoked',
            'failed'           => 'Failed',
            'expired'          => 'Expired',
        ],
        'category' => [
            'payment'      => 'Payment',
            'notification' => 'Notification',
            'social_oidc'  => 'Social sign-in',
            'stream'       => 'Streaming',
            'meeting'      => 'Meeting',
            'learning'     => 'Learning',
            'geocoding'    => 'Geocoding',
        ],
    ],
    'settingShow' => [
        'metaTitle' => 'Setting',
        'heading' => 'Organization setting',
        'sub' => 'A single configured setting and its resolved value.',
        'keyLbl' => 'Key',
        'valueLbl' => 'Value',
        'notSet' => 'Not set',
    ],
];
