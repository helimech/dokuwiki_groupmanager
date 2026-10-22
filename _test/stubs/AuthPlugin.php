<?php

namespace dokuwiki\Extension;

class AuthPlugin
{
    public array $users = [];
    public bool $write = true;

    public function canDo($key)
    {
        return $key === 'getUsers'
            || $key === 'getGroups'
            || (($key === 'modGroups' || $key === 'addUser') && $this->write);
    }

    public function retrieveUsers($start = 0, $limit = 0, $filter = [])
    {
        return $this->users;
    }

    public function retrieveGroups($start = 0, $limit = 0)
    {
        $groups = [];
        foreach ($this->users as $record) {
            $groups = array_merge($groups, $record['grps'] ?? []);
        }
        return array_unique($groups);
    }

    public function getUserData($name)
    {
        return $this->users[$name] ?? false;
    }

    public function triggerUserMod($type, $params)
    {
        if ($type === 'create') {
            if (isset($this->users[$params[0]])) {
                return false;
            }
            $this->users[$params[0]] = [
                'name' => $params[2],
                'mail' => $params[3],
                'grps' => $params[4],
                'pass' => 'hash:' . hash('sha256', $params[1]),
            ];
            return true;
        }

        $this->users[$params[0]] = array_merge($this->users[$params[0]], $params[1]);
        return true;
    }

    public function cleanGroup($name)
    {
        return $name;
    }

    public function cleanUser($name)
    {
        return $name;
    }

    public function isCaseSensitive()
    {
        return true;
    }
}
