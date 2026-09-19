<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * No test may reach the network.
         *
         * This CRM holds live Meta credentials in its settings table, so an
         * un-faked HTTP call in a test is not merely slow — it can read from,
         * or write to, a real WhatsApp Business account and a real ad account.
         * A full run once took seven hours because tests were waiting on
         * genuine Graph API timeouts.
         *
         * preventStrayRequests() turns any un-faked outbound request into an
         * immediate, obvious failure. A test that needs a Graph response should
         * fake it explicitly with Http::fake().
         */
        Http::preventStrayRequests();
    }
}
