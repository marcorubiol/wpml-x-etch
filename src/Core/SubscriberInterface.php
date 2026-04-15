<?php
/**
 * Subscriber interface for hook registration.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\Core;

/**
 * Classes that need WordPress hooks implement this interface.
 *
 * Each entry in the returned array maps a hook name to a callback spec:
 *   'hook_name' => 'method_name'                        (priority 10, 1 arg)
 *   'hook_name' => ['method_name', priority]             (custom priority, 1 arg)
 *   'hook_name' => ['method_name', priority, arg_count]  (custom priority and args)
 *
 * When a class needs multiple callbacks on the same hook, use a numeric outer array:
 *   [
 *     ['hook_name', 'method_a', 10, 1],
 *     ['hook_name', 'method_b', 20, 2],
 *   ]
 */
interface SubscriberInterface {

	/**
	 * @return array<string, string|array>|array<int, array>
	 */
	public static function getSubscribedEvents(): array;
}
