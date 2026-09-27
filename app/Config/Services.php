<?php

namespace Config;

use CodeIgniter\Config\BaseService;
use CodeIgniter\HTTP\UserAgent;
use Config\App;
use WBS\Shared\Http\WbsIncomingRequest;

/**
 * Services Configuration file.
 *
 * Services are simply other classes/libraries that the system uses
 * to do its job. This is used by CodeIgniter to allow the core of the
 * framework to be swapped out easily without affecting the usage within
 * the rest of your application.
 *
 * This file holds any application-specific services, or service overrides
 * that you might need. An example has been included with the general
 * method format you should use for your service methods. For more examples,
 * see the core Services file at system/Config/Services.php.
 */
class Services extends BaseService
{
    /**
     * Override the HTTP request with WbsIncomingRequest, which DECLARES the
     * server-side auth-context properties AuthFilter attaches (wbsUserId,
     * wbsOrgId, wbsMfaLevel, wbsScopes, wbsTokenId, wbsSessionId). This removes
     * the PHP 8.2+ dynamic-property deprecation without turning that context into
     * client-spoofable headers. Signature mirrors the core factory.
     *
     * @return \CodeIgniter\HTTP\IncomingRequest
     *
     * @internal
     */
    public static function incomingrequest(?App $config = null, bool $getShared = true)
    {
        if ($getShared) {
            return static::getSharedInstance('request', $config);
        }

        $config ??= config(App::class);

        return new WbsIncomingRequest(
            $config,
            static::get('uri'),
            'php://input',
            new UserAgent(),
        );
    }
}
