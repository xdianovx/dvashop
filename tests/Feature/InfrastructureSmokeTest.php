<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('home page opens', function () {
    $this->get('/')
        ->assertOk();
});

test('admin panel redirects guest to login', function () {
    $response = $this->get('/admin');

    $response->assertRedirect();

    expect($response->headers->get('Location'))->toContain('/admin/login');
});

test('super admin can access admin panel', function () {
    $user = User::factory()->superAdmin()->create();

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk();
});
