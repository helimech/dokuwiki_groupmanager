<?php

class FakeInput
{
    public $server;

    public function __construct()
    {
        $this->server = new FakeServer();
    }
}
