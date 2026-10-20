<?php
/** AJAX controller for Group Manager. @license GPL-2.0-or-later */
use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;

class action_plugin_groupmanager extends ActionPlugin
{
    public function register(EventHandler $controller)
    {
        $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'ajax');
    }

    public function ajax(Event $event, $param)
    {
        if ($event->data !== 'plugin_groupmanager') return;
        $event->preventDefault();
        $event->stopPropagation();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        global $INPUT;
        if (!auth_isadmin()) {
            $this->reply(403, ['ok' => false, 'error' => 'Superuser access required.']);
            return;
        }
        if ($INPUT->server->str('REQUEST_METHOD') !== 'POST') {
            $this->reply(405, ['ok' => false, 'error' => 'POST required.']);
            return;
        }
        $token = $INPUT->str('sectok');
        if ($token === '' || !hash_equals((string) getSecurityToken(), $token)) {
            $this->reply(403, ['ok' => false, 'error' => 'Security token expired. Reload the page.']);
            return;
        }
        /** @var helper_plugin_groupmanager|null $helper */
        $helper = plugin_load('helper', 'groupmanager');
        if (!$helper) {
            $this->reply(500, ['ok' => false, 'error' => 'Membership service not available.']);
            return;
        }
        try {
            $action = $INPUT->str('gm_action');
            switch ($action) {
                case 'directory':
                    $result = $helper->directoryData(true);
                    break;
                case 'save':
                    $edits = json_decode($INPUT->str('edits'), true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($edits) || !array_is_list($edits)) throw new InvalidArgumentException('Invalid edit list.');
                    $override = $INPUT->str('privileged_override') === '1';
                    if ($override && $INPUT->str('override_confirmation') !== 'PRIVILEGED') {
                        throw new InvalidArgumentException('Privileged override confirmation missing.');
                    }
                    $result = $helper->saveMemberships($edits, $override);
                    break;
                case 'saveTemplate':
                    $groups = json_decode($INPUT->str('groups'), true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($groups) || !array_is_list($groups)) throw new InvalidArgumentException('Invalid template groups.');
                    $result = $helper->saveTemplate($INPUT->str('id'), $INPUT->str('name'), $groups);
                    break;
                case 'deleteTemplate':
                    $result = $helper->deleteTemplate($INPUT->str('id'));
                    break;
                case 'createUser':
                    $groups = json_decode($INPUT->str('groups'), true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($groups) || !array_is_list($groups)) throw new InvalidArgumentException('Invalid membership list.');
                    $result = $helper->createUser($INPUT->str('username'), $INPUT->str('name'), $INPUT->str('email'), $groups);
                    break;
                case 'createGroup':
                    $result = $helper->createGroup($INPUT->str('name'));
                    break;
                case 'forgetGroup':
                    $result = $helper->forgetGroup($INPUT->str('name'));
                    break;
                default:
                    throw new InvalidArgumentException('Unsupported operation.');
            }
            $this->reply(200, ['ok' => true, 'data' => $result]);
        } catch (InvalidArgumentException | JsonException $e) {
            $this->reply(400, ['ok' => false, 'error' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->reply(409, ['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    private function reply(int $status, array $value): void
    {
        http_response_code($status);
        echo json_encode($value, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
