<?php
/**
 * Standalone regression test for nested component props.
 *
 * Covers the full round trip for every nesting Etch supports — group,
 * condition wrapper and repeater — across the three places the plugin walks
 * it: string extraction (ComponentParser), applying translations to a
 * translated post (ContentTranslationHandler) and injecting translated
 * defaults at render time (TemplateTranslator). 1.2.7 fixed the first two and
 * left the third flat, which is the regression this file exists to catch.
 *
 * No PHPUnit, no WordPress: run it with any PHP 8.1+ binary.
 *
 *     php tests/nested-props.php
 *
 * @package WpmlXEtch
 */

declare(strict_types=1);

// ---- Minimal WordPress surface used by the classes under test -------------
$GLOBALS['__meta']  = array();
$GLOBALS['__posts'] = array();
$GLOBALS['__blocks'] = array();

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}
function get_post( $id ) {
	return $GLOBALS['__posts'][ $id ] ?? null;
}
function get_post_type( $id ) {
	return $GLOBALS['__posts'][ $id ]->post_type ?? '';
}
function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['__meta'][ $id ][ $key ] ?? '';
}
function parse_blocks( $content ) {
	return $GLOBALS['__blocks'][ $content ] ?? array();
}

define( 'ZS_WXE_VERSION', 'test' );
require __DIR__ . '/../src/Core/Plugin.php';
require __DIR__ . '/../src/Etch/ComponentParser.php';

use WpmlXEtch\Etch\ComponentParser;

// ---- Tiny assertion helpers ----------------------------------------------
$GLOBALS['__fail'] = 0;
function check( string $label, $actual, $expected ): void {
	if ( $actual === $expected ) {
		echo "  ok   $label\n";
		return;
	}
	$GLOBALS['__fail']++;
	echo "  FAIL $label\n";
	echo "       expected: " . var_export( $expected, true ) . "\n";
	echo "       actual:   " . var_export( $actual, true ) . "\n";
}

// ---- Fixtures: one component exercising every nesting Etch supports -------
const COMPONENT_ID = 100;

$prop_defs = array(
	array(
		'key'        => 'content',
		'name'       => 'Content',
		'type'       => array( 'primitive' => 'object', 'specialized' => 'group' ),
		'properties' => array(
			array( 'key' => 'eyebrow', 'type' => array( 'primitive' => 'string' ), 'default' => 'Trusted by road marking contractors across Europe' ),
			array( 'key' => 'title', 'type' => array( 'primitive' => 'string' ), 'default' => 'Hero title' ),
			array(
				'key'        => 'cta',
				'type'       => array( 'primitive' => 'object', 'specialized' => 'group' ),
				'properties' => array(
					array( 'key' => 'label', 'type' => array( 'primitive' => 'string' ), 'default' => 'Get a quote' ),
				),
			),
		),
	),
	array(
		'key'        => 'showLede',
		'type'       => array( 'primitive' => 'string', 'specialized' => 'condition' ),
		'default'    => '{true}',
		'properties' => array(
			array( 'key' => 'lede', 'type' => array( 'primitive' => 'string' ), 'default' => 'Lede paragraph' ),
		),
	),
	array(
		'key'        => 'features',
		'type'       => array( 'primitive' => 'array', 'specialized' => 'repeater' ),
		'properties' => array(
			array( 'key' => 'heading', 'type' => array( 'primitive' => 'string' ), 'default' => 'Feature heading' ),
			array( 'key' => 'body', 'type' => array( 'primitive' => 'string' ) ),
		),
		'default'    => array(
			array( 'body' => 'First feature body' ),
			array( 'heading' => 'Second feature', 'body' => 'Second feature body' ),
		),
	),
	array( 'key' => 'badge', 'type' => array( 'primitive' => 'string' ), 'default' => 'New' ),
	// Same default text as cta.label, but a specialized type: never translatable.
	array( 'key' => 'link', 'type' => array( 'primitive' => 'string', 'specialized' => 'url' ), 'default' => 'Get a quote' ),
);

$GLOBALS['__meta'][ COMPONENT_ID ]['etch_component_properties'] = $prop_defs;
$GLOBALS['__posts'][ COMPONENT_ID ] = (object) array( 'ID' => COMPONENT_ID, 'post_type' => 'wp_block', 'post_content' => 'component-content' );
$GLOBALS['__blocks']['component-content'] = array();

// Byte-exact Etch serialization, written by hand (not by the encoder).
$group_value    = '{{"eyebrow":"Custom eyebrow"}}';
$repeater_value = '{[{"heading":"Instance A","body":"Body A"},{"heading":"Instance B"}]}';

echo "== serialization matches Etch's builder output ==\n";
check( 'group encode', ComponentParser::encode_composite_value( array( 'eyebrow' => 'Custom eyebrow' ) ), $group_value );
check( 'repeater encode', ComponentParser::encode_composite_value( array(
	array( 'heading' => 'Instance A', 'body' => 'Body A' ),
	array( 'heading' => 'Instance B' ),
) ), $repeater_value );
check( 'group decode', ComponentParser::decode_composite_value( $group_value ), array( 'eyebrow' => 'Custom eyebrow' ) );
check( 'repeater decode is a list', array_is_list( (array) ComponentParser::decode_composite_value( $repeater_value ) ), true );
check( 'dynamic binding is not a payload', ComponentParser::decode_composite_value( '{{post.title}}' ), null );

// ---- Registration ---------------------------------------------------------
echo "\n== registration: every default and instance value is extracted ==\n";
$parser = new ComponentParser();

$defaults = array();
$m = new ReflectionMethod( ComponentParser::class, 'collect_default_values' );
$m->setAccessible( true );
$m->invokeArgs( $parser, array( $prop_defs, &$defaults ) );
sort( $defaults );
check( 'prop defaults', $defaults, array(
	'Feature heading',
	'First feature body',
	'Get a quote',
	'Hero title',
	'Lede paragraph',
	'New',
	'Second feature',
	'Second feature body',
	'Trusted by road marking contractors across Europe',
) );

$tree_m = new ReflectionMethod( ComponentParser::class, 'build_translatable_tree' );
$tree_m->setAccessible( true );
$tree = $tree_m->invoke( $parser, $prop_defs );
check( 'tree shape', $tree, array(
	'content'  => array( 'eyebrow' => true, 'title' => true, 'cta' => array( 'label' => true ) ),
	'lede'     => true,
	'features' => array( 'heading' => true, 'body' => true ),
	'badge'    => true,
) );

$inst_values = array();
$inst_m = new ReflectionMethod( ComponentParser::class, 'collect_from_instance_values' );
$inst_m->setAccessible( true );
$inst_m->invokeArgs( $parser, array(
	array(
		'content'  => '{{"eyebrow":"Custom eyebrow","cta":"{{\"label\":\"Book a call\"}}"}}',
		'features' => $repeater_value,
		'badge'    => 'Sale',
		'link'     => 'https://example.com',
	),
	$tree,
	&$inst_values,
) );
sort( $inst_values );
check( 'instance values', $inst_values, array( 'Body A', 'Book a call', 'Custom eyebrow', 'Instance A', 'Instance B', 'Sale' ) );


// ---- Injection ------------------------------------------------------------
require __DIR__ . '/../src/Core/SubscriberInterface.php';
require __DIR__ . '/../src/Utils/Logger.php';
require __DIR__ . '/../src/WPML/StringHandler.php';
require __DIR__ . '/../src/WPML/TemplateTranslator.php';
require __DIR__ . '/../src/WPML/ContentTranslationHandler.php';

use WpmlXEtch\WPML\TemplateTranslator;
use WpmlXEtch\WPML\ContentTranslationHandler;

$map = array(
	'Trusted by road marking contractors across Europe' => 'Betrodd av vagmarkeringsentreprenorer',
	'Hero title'          => 'Hjaltetitel',
	'Get a quote'         => 'Fa en offert',
	'Lede paragraph'      => 'Ingresstycke',
	'Feature heading'     => 'Funktionsrubrik',
	'First feature body'  => 'Forsta funktionstexten',
	'Second feature'      => 'Andra funktionen',
	'Second feature body' => 'Andra funktionstexten',
	'New'                 => 'Ny',
);

$translator = new TemplateTranslator();
$inject = new ReflectionMethod( TemplateTranslator::class, 'inject_defaults' );
$inject->setAccessible( true );

function inject_into( $translator, $inject, array $prop_defs, array $attrs, array $map ): array {
	$args = array( $prop_defs, &$attrs, $map );
	$inject->invokeArgs( $translator, $args );
	return $attrs;
}

echo "\n== injection: instance leaves everything unset ==\n";
$out = inject_into( $translator, $inject, $prop_defs, array(), $map );
check( 'group default, nested group included', $out['content'] ?? null,
	'{{"eyebrow":"Betrodd av vagmarkeringsentreprenorer","title":"Hjaltetitel","cta":"{{\\"label\\":\\"Fa en offert\\"}}"}}' );
check( 'condition child hoisted to top level', $out['lede'] ?? null, 'Ingresstycke' );
check( 'repeater default payload + per-item leaf default', $out['features'] ?? null,
	'{[{"body":"Forsta funktionstexten","heading":"Funktionsrubrik"},{"heading":"Andra funktionen","body":"Andra funktionstexten"}]}' );
check( 'top-level leaf (already worked before)', $out['badge'] ?? null, 'Ny' );
check( 'specialized prop is never filled', array_key_exists( 'link', $out ), false );

echo "\n== injection: instance values are left alone ==\n";
$out = inject_into( $translator, $inject, $prop_defs, array(
	'content' => '{{"eyebrow":"Custom eyebrow"}}',
	'badge'   => 'Sale',
), $map );
check( 'explicit leaf inside a group survives', $out['content'] ?? null,
	'{{"eyebrow":"Custom eyebrow","title":"Hjaltetitel","cta":"{{\\"label\\":\\"Fa en offert\\"}}"}}' );
check( 'explicit top-level leaf survives', $out['badge'] ?? null, 'Sale' );

echo "\n== injection: a dynamic binding is never rewritten ==\n";
$out = inject_into( $translator, $inject, $prop_defs, array( 'content' => '{{post.hero}}' ), $map );
check( 'dynamic group binding untouched', $out['content'] ?? null, '{{post.hero}}' );

echo "\n== injection: explicit repeater items keep order, gain leaf defaults ==\n";
$out = inject_into( $translator, $inject, $prop_defs, array(
	'features' => '{[{"heading":"Instance A","body":"Body A"},{"body":"Body B"}]}',
), $map );
check( 'item count and order preserved', $out['features'] ?? null,
	'{[{"heading":"Instance A","body":"Body A"},{"body":"Body B","heading":"Funktionsrubrik"}]}' );

echo "\n== injection is idempotent ==\n";
$once  = inject_into( $translator, $inject, $prop_defs, array(), $map );
$twice = inject_into( $translator, $inject, $prop_defs, $once, $map );
check( 'second pass changes nothing', $twice, $once );

echo "\n== content translation: repeater instance values ==\n";
$handler = new ContentTranslationHandler();
$tp = new ReflectionMethod( ContentTranslationHandler::class, 'translate_prop_value' );
$tp->setAccessible( true );
$content_map = array( 'Instance A' => 'Instans A', 'Body A' => 'Text A', 'Book a call' => 'Boka ett samtal' );
check( 'repeater leaves translated in place', $tp->invoke( $handler, '{[{"heading":"Instance A","body":"Body A"},{"heading":"Instance B"}]}', $content_map ),
	'{[{"heading":"Instans A","body":"Text A"},{"heading":"Instance B"}]}' );
check( 'nested group inside a group still works', $tp->invoke( $handler, '{{"cta":"{{\\"label\\":\\"Book a call\\"}}"}}', $content_map ),
	'{{"cta":"{{\\"label\\":\\"Boka ett samtal\\"}}"}}' );
check( 'no match leaves the value byte-identical', $tp->invoke( $handler, '{[{"heading":"Untouched"}]}', $content_map ),
	'{[{"heading":"Untouched"}]}' );
check( 'plain string still translated', $tp->invoke( $handler, 'Instance A', $content_map ), 'Instans A' );


echo "\n== the 1.2.7 injector, replayed on the same fixture ==\n";
$old = array();
foreach ( $prop_defs as $prop ) {
	$key     = $prop['key'] ?? '';
	$default = $prop['default'] ?? null;
	if ( empty( $key ) || ! is_string( $default ) || '' === $default ) {
		continue;
	}
	if ( preg_match( \WpmlXEtch\Core\Plugin::DYNAMIC_EXPR_PATTERN, $default ) ) {
		continue;
	}
	if ( isset( $old[ $key ] ) ) {
		continue;
	}
	$translated = $map[ $default ] ?? null;
	if ( $translated ) {
		$old[ $key ] = $translated;
	}
}
check( 'old injector reached only the flat leaf, and filled a url prop', $old,
	array( 'badge' => 'Ny', 'link' => 'Fa en offert' ) );

echo "\n" . ( 0 === $GLOBALS['__fail'] ? "ALL PASS\n" : "{$GLOBALS['__fail']} FAILURES\n" );
exit( $GLOBALS['__fail'] > 0 ? 1 : 0 );
