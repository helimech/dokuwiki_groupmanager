<?php

class FakeServer
{
    public function str($name, $default = '')
    {
        return $name === 'REMOTE_USER' ? 'test-admin' : $default;
    }
}
