<?php

declare(strict_types=1);

/**
 * Geo module UI strings. English is the guaranteed fallback: every key here MUST
 * exist so a missing translation degrades to English rather than a raw key. The
 * venues page is SELF-CONTAINED (own <html>) so it includes _locale.php for a
 * locale-aware <html lang dir> (RTL for Arabic). Counts are chosen in PHP; {0}
 * placeholders are interpolated via $li().
 *
 * Venue `status` is a FIXED vocabulary, localized with a raw-value fallback.
 * venue_type is a larger server vocabulary shown verbatim (humanized in-view).
 */

return [
    'metaTitle'   => 'Venues — Locations',
    'heading'     => 'Venue directory',
    'sub'         => 'Facilities registered for this organization.',
    'count'       => '{0} of {1} venues',
    'empty'       => 'No venues match the current filters.',
    'colName'     => 'Name',
    'colType'     => 'Type',
    'colStatus'   => 'Status',
    'colCapacity' => 'Capacity',
    'colCoords'   => 'Coordinates',
    'noCoords'    => 'Not geolocated',
    'private'     => 'Private',
    'discoverable' => 'Discoverable',

    // Venue status — fixed vocabulary, localized with raw-value fallback.
    'status' => [
        'active'      => 'Active',
        'inactive'    => 'Inactive',
        'maintenance' => 'Maintenance',
        'closed'      => 'Closed',
    ],
    'venueType' => [
        'church' => 'Church',
        'hall' => 'Hall',
        'conference_center' => 'Conference center',
        'classroom' => 'Classroom',
        'outdoor' => 'Outdoor',
        'fellowship_hall' => 'Fellowship hall',
        'auditorium' => 'Auditorium',
        'training_center' => 'Training center',
        'other' => 'Other',
    ],
    'discovery' => [
        'private' => 'Private',
        'unlisted' => 'Unlisted',
        'public' => 'Public',
    ],
    'venueView' => [
        'colActions' => 'Actions',
        'newVenue' => 'New venue',
        'edit' => 'Edit',
        'delete' => 'Delete',
        'deleteConfirm' => 'Delete this venue? It will be closed and hidden.',
    ],
    'venueForm' => [
        'metaTitleNew' => 'New venue — Locations',
        'metaTitleEdit' => 'Edit venue — Locations',
        'headingNew' => 'New venue',
        'headingEdit' => 'Edit venue',
        'sub' => 'Facility details. A venue needs a name and either coordinates or an address/region.',
        'backToList' => 'Back to venues',
        'cancel' => 'Cancel',
        'saveNew' => 'Create venue',
        'saveEdit' => 'Save changes',
        'createdFlash' => 'Venue created.',
        'updatedFlash' => 'Venue updated.',
        'deletedFlash' => 'Venue deleted.',
        'nameLabel' => 'Name',
        'namePh' => 'e.g. Central Hall',
        'typeLabel' => 'Type',
        'statusLabel' => 'Status',
        'capacityLabel' => 'Capacity',
        'capacityHint' => 'seats (optional)',
        'discoveryLabel' => 'Discovery',
        'discoveryHint' => 'who can find it',
        'addressLabel' => 'Address',
        'addressPh' => 'Street address or description',
        'locationHint' => 'address or coordinates required',
        'latLabel' => 'Latitude',
        'lngLabel' => 'Longitude',
        'phoneLabel' => 'Contact phone',
        'emailLabel' => 'Contact email',
        'websiteLabel' => 'Website',
        'parkingLabel' => 'Parking / access notes',
    ],

    // Admin global->local venue directory (/venues/directory, venue_geo_directory.php).
    'venueDirectory' => [
        'metaTitle'   => 'Venue map — Locations',
        'heading'     => 'Venue map',
        'intro'       => 'Every venue by location, with the groups assigned to each.',
        'summary'     => '{0} {1} across {2} {3}',
        'venue'       => 'venue',
        'venues'      => 'venues',
        'country'     => 'country',
        'countries'   => 'countries',
        'none'        => 'No venues registered yet.',
        'noGroups'    => 'No groups assigned',
        'unlocated'   => 'Unlocated venues',
        'unspecified' => 'Unspecified',
        'capacity'    => 'Cap {0}',
        'private'     => 'Private',
        'unlisted'    => 'Unlisted',
        'publicLabel' => 'Public',
        'primary'     => 'Primary',
        'secondary'   => 'Secondary',
        'overflow'    => 'Overflow',
        'edit'        => 'Edit venue',
        'venueFallback' => 'Venue',
    ],
];
