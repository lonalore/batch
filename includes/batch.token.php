<?php

/**
 * @file
 * Functions to generates tokens.
 */

/**
 * Generates a token based on $value, the user session, and the private key.
 *
 * @param string $value
 *   An additional value to base the token on.
 *
 * @return string
 *  A 43-character URL-safe token for validation.
 */
function batch_get_token($value = '')
{
	return batch_hmac_base64($value, session_id());
}

/**
 * Calculates a base-64 encoded, URL-safe sha-256 hmac.
 *
 * @param string $data
 *  String to be validated with the hmac.
 * @param string $key
 *  A secret string key.
 *
 * @return string
 *  A base-64 encoded sha-256 hmac, with + replaced with -, / with _ and any = padding characters removed.
 */
function batch_hmac_base64($data, $key)
{
	// Casting $data and $key to strings here is necessary to avoid empty string
	// results of the hash function if they are not scalar values. As this
	// function is used in security-critical contexts like token validation it is
	// important that it never returns an empty string.
	$hmac = base64_encode(hash_hmac('sha256', (string) $data, (string) $key, true));
	// Modify the hmac so it's safe to use in URLs.
	return strtr($hmac, array('+' => '-', '/' => '_', '=' => ''));
}
