<?php
/**
 * AI provider client: builds prompts, calls Claude/OpenAI APIs, parses responses.
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

namespace WpmlXEtch\AI;

class AiClient {

	private readonly AiSettings $settings;

	public function __construct( AiSettings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Translate strings via the configured AI provider.
	 *
	 * @param string[] $strings     Source strings to translate.
	 * @param string   $source_lang Source language name (e.g. "English").
	 * @param string   $target_lang Target language name (e.g. "Español").
	 * @param array    $options     {tone, glossary, page_title}
	 * @return array<string,string>|\WP_Error Map of original => translated.
	 */
	public function translate( array $strings, string $source_lang, string $target_lang, array $options = array() ): array|\WP_Error {
		$provider = $this->settings->get_provider();
		$api_key  = $this->settings->get_api_key();

		if ( ! $provider || ! $api_key ) {
			return new \WP_Error( 'not_configured', 'AI provider not configured', array( 'status' => 400 ) );
		}

		$prompt = $this->build_prompt( $strings, $source_lang, $target_lang, $options );

		return match ( $provider ) {
			'claude' => $this->call_claude( $api_key, $prompt, $strings ),
			'openai' => $this->call_openai( $api_key, $prompt, $strings ),
			default  => new \WP_Error( 'invalid_provider', 'Unknown AI provider: ' . $provider ),
		};
	}

	public function test(): array|\WP_Error {
		$provider = $this->settings->get_provider();
		$api_key  = $this->settings->get_api_key();

		if ( ! $provider || ! $api_key ) {
			return new \WP_Error( 'not_configured', 'AI provider not configured', array( 'status' => 400 ) );
		}

		// Minimal call to verify key works.
		$test_strings = array( 'Hello' );
		$result       = $this->translate( $test_strings, 'English', 'Spanish', array( 'tone' => 'formal' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'success' => true, 'provider' => $provider );
	}

	private function build_prompt( array $strings, string $source_lang, string $target_lang, array $options ): string {
		$tone       = $options['tone'] ?? $this->settings->get_tone();
		$glossary   = $options['glossary'] ?? $this->settings->get_glossary();
		$page_title = $options['page_title'] ?? '';

		$system  = "You are a professional translator. Translate the following strings from {$source_lang} to {$target_lang}.\n\n";
		$system .= "Tone: {$tone}\n";

		if ( $page_title ) {
			$system .= "Context: These strings appear on a web page titled \"{$page_title}\".\n";
		}

		if ( ! empty( $glossary ) ) {
			$system .= "\nGlossary — use these translations when applicable:\n";
			foreach ( $glossary as $entry ) {
				if ( ! isset( $entry['source'], $entry['target'] ) ) {
					continue;
				}
				$lang      = $entry['lang'] ?? '';
				$lang_note = $lang ? " ({$lang})" : '';
				$system   .= "- \"{$entry['source']}\" → \"{$entry['target']}\"{$lang_note}\n";
			}
		}

		$system .= "\nReturn a JSON object mapping each original string to its translation. ";
		$system .= "Do not add explanations. Preserve HTML tags if present. ";

		$system .= "Do not translate URLs, email addresses, or code.";

		return $system;
	}

	private function call_claude( string $api_key, string $system_prompt, array $strings ): array|\WP_Error {
		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 30,
			'headers' => array(
				'x-api-key'         => $api_key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			),
			'body' => wp_json_encode( array(
				'model'       => 'claude-sonnet-4-20250514',
				'max_tokens'  => 4096,
				'temperature' => 0,
				'system'      => $system_prompt,
				'messages'    => array(
					array(
						'role'    => 'user',
						'content' => wp_json_encode( $strings ),
					),
				),
			) ),
		) );

		return $this->parse_response( $response, 'claude', $strings );
	}

	private function call_openai( string $api_key, string $system_prompt, array $strings ): array|\WP_Error {
		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body' => wp_json_encode( array(
				'model'       => 'gpt-4o-mini',
				'temperature' => 0,
				'messages'    => array(
					array( 'role' => 'system', 'content' => $system_prompt ),
					array( 'role' => 'user', 'content' => wp_json_encode( $strings ) ),
				),
			) ),
		) );

		return $this->parse_response( $response, 'openai', $strings );
	}

	/**
	 * @param array|\WP_Error $response
	 */
	private function parse_response( $response, string $provider, array $strings ): array|\WP_Error {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'api_error', $response->get_error_message(), array( 'status' => 502 ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 401 === $code || 403 === $code ) {
			return new \WP_Error( 'invalid_key', 'Invalid API key', array( 'status' => 401 ) );
		}
		if ( 429 === $code ) {
			return new \WP_Error( 'rate_limited', 'Rate limited — try again in a moment', array( 'status' => 429 ) );
		}
		if ( $code >= 500 ) {
			return new \WP_Error( 'provider_error', 'AI provider error', array( 'status' => 502 ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			return new \WP_Error( 'api_error', 'Unexpected response: ' . $code, array( 'status' => 502 ) );
		}

		$body = wp_remote_retrieve_body( $response );

		// Extract text content from provider response.
		$text = '';
		if ( 'claude' === $provider ) {
			$data = json_decode( $body, true );
			$text = $data['content'][0]['text'] ?? '';
		} else {
			$data = json_decode( $body, true );
			$text = $data['choices'][0]['message']['content'] ?? '';
		}

		if ( ! $text ) {
			return new \WP_Error( 'empty_response', 'Empty response from AI provider', array( 'status' => 502 ) );
		}

		// Strip markdown code fences if present.
		$text = preg_replace( '/^```(?:json)?\s*/m', '', $text );
		$text = preg_replace( '/\s*```\s*$/m', '', $text );
		$text = trim( $text );

		$translations = json_decode( $text, true );
		if ( ! is_array( $translations ) ) {
			return new \WP_Error( 'parse_error', 'Invalid JSON response from AI provider', array( 'status' => 502 ) );
		}

		// Validate response quality: drop empty values and warn about missing keys.
		$validated = array();
		foreach ( $strings as $original ) {
			if ( ! isset( $translations[ $original ] ) ) {
				continue;
			}
			$translated = $translations[ $original ];
			if ( ! is_string( $translated ) || '' === trim( $translated ) ) {
				continue; // Skip empty or non-string values.
			}
			$validated[ $original ] = $translated;
		}

		if ( empty( $validated ) ) {
			return new \WP_Error( 'no_translations', 'AI returned no usable translations', array( 'status' => 502 ) );
		}

		return $validated;
	}
}
