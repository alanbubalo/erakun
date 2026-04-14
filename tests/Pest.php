<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class)->in('Feature');
uses(TestCase::class, RefreshDatabase::class)->in('Unit');
