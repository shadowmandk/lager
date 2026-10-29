<?php

it('sends security headers and asks search engines to stay away', function () {
    $this->get('/laas')
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
});

it('shows Danish error pages', function () {
    $this->get('/findes-ikke')->assertNotFound()->assertSee('Siden findes ikke');
});

it('keeps the board out of the browser cache', function () {
    expect($this->withSession(unlocked())->get('/')->headers->get('Cache-Control'))->toContain('no-store');
});
