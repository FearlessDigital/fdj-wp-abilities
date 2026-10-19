<?php
/**
 * Smoke tests for the pure logic in class-fdj-mcp-annual-reset.php.
 *
 * Runs with plain PHP, no WordPress and no Gravity Forms needed:
 *
 *     php tests/annual-reset-smoke.php
 *
 * These cover the decisions that protect real data (what trash-entries will
 * refuse, how update-form keeps conditional logic in step with a renamed
 * choice, what counts as a menu or product problem). They do NOT cover the
 * calls into WordPress, Gravity Forms or WooCommerce; test those on a staging
 * copy before a release.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );

// Stand-ins for the few WordPress functions the file touches at load time.
function add_action() {}
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_parse_url( $u, $c = -1 ) { return parse_url( $u, $c ); }

class GFAPI {}
class WooCommerce {}
class FDJ_MCP_GravityForms {
	public static function can_view_gf_entries() { return true; }
	public static function can_view_gf_forms() { return true; }
}

require __DIR__ . '/../includes/class-fdj-mcp-annual-reset.php';

$pass = 0;
$fail = 0;

function ok( $cond, $label ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "  ok    $label\n";
	} else {
		++$fail;
		echo "  FAIL  $label\n";
	}
}

function field( $id, $type, $label, $extra = array() ) {
	return (object) array_merge( array( 'id' => $id, 'type' => $type, 'label' => $label, 'description' => '', 'cssClass' => '', 'isRequired' => false, 'conditionalLogic' => '' ), $extra );
}

echo "definitions\n";
$defs  = FDJ_MCP_Annual_Reset::get_definitions();
$names = array_keys( $defs );
ok( 11 === count( $defs ), 'eleven abilities when Gravity Forms and WooCommerce are present (got ' . count( $defs ) . ')' );
ok( count( $names ) === count( array_unique( $names ) ), 'names are unique' );
$bad = array();
foreach ( $defs as $n => $d ) {
	if ( ! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $n ) ) { $bad[] = "$n name"; }
	if ( ! is_callable( $d['execute_callback'] ) ) { $bad[] = "$n execute"; }
	if ( ! is_callable( $d['permission_callback'] ) ) { $bad[] = "$n permission"; }
	if ( 'object' !== $d['input_schema']['type'] ) { $bad[] = "$n input"; }
	if ( 'object' !== $d['output_schema']['type'] ) { $bad[] = "$n output"; }
	if ( ! isset( $d['is_write'], $d['requires'], $d['label'], $d['description'], $d['category'], $d['annotations'] ) ) { $bad[] = "$n keys"; }
	foreach ( ( isset( $d['input_schema']['required'] ) ? $d['input_schema']['required'] : array() ) as $r ) {
		if ( ! isset( $d['input_schema']['properties'][ $r ] ) ) { $bad[] = "$n required $r"; }
	}
}
ok( ! $bad, 'every definition is well formed and callable' . ( $bad ? ': ' . implode( ', ', $bad ) : '' ) );
$writes = array_keys( array_filter( $defs, function ( $d ) { return $d['is_write']; } ) );
sort( $writes );
ok( $writes === array( 'fdj/set-product-fields', 'gravityforms/export-entries', 'gravityforms/restore-form', 'gravityforms/trash-entries', 'gravityforms/update-form' ), 'exactly the five expected abilities are marked as writes' );
ok( ! $defs['fdj/list-order-items']['is_write'] && isset( $defs['fdj/list-order-items']['input_schema']['properties']['include_email'] ), 'list-order-items is read-only and email is opt-in' );
ok( false === $defs['fdj/list-order-items']['input_schema']['properties']['include_email']['default'], 'email defaults to off' );
ok( ! isset( FDJ_MCP_Annual_Reset::get_definitions()['gravityforms/trash-entries']['input_schema']['properties']['everything'] ), 'trash-entries has no "everything" switch' );
ok( in_array( 'date_end', $defs['gravityforms/trash-entries']['input_schema']['required'], true ), 'trash-entries requires date_end' );

echo "\ntrash_decision\n";
$base = array( 'count' => 10, 'expected' => 10, 'current_year_count' => 0, 'allow_current_year' => false, 'export_covers' => true, 'dry_run' => false );
$t = function ( $over ) use ( $base ) { return FDJ_MCP_Annual_Reset::trash_decision( array_merge( $base, $over ) ); };
ok( $t( array() )['ok'], 'allowed when exported, counted and not this year' );
ok( ! $t( array( 'export_covers' => false ) )['ok'] && 'fdj_gf_not_exported' === $t( array( 'export_covers' => false ) )['code'], 'refused without a covering export' );
ok( 'fdj_gf_count_mismatch' === $t( array( 'expected' => 9 ) )['code'], 'refused when expected_count is wrong' );
ok( 'fdj_gf_count_mismatch' === $t( array( 'expected' => -1 ) )['code'], 'refused when expected_count is missing' );
ok( 'fdj_gf_current_year' === $t( array( 'current_year_count' => 2 ) )['code'], 'refused when entries are from this year' );
ok( $t( array( 'current_year_count' => 2, 'allow_current_year' => true ) )['ok'], 'this year allowed only when asked' );
ok( $t( array( 'dry_run' => true, 'export_covers' => false, 'expected' => -1 ) )['ok'], 'dry run needs no export and no count' );
ok( 'fdj_gf_current_year' === $t( array( 'dry_run' => true, 'current_year_count' => 1 ) )['code'], 'dry run still warns about this year' );
ok( 'fdj_gf_too_many' === $t( array( 'count' => 2001, 'expected' => 2001 ) )['code'], 'refused above 2000 entries' );
ok( 'nothing_to_do' === $t( array( 'count' => 0, 'expected' => 0 ) )['code'], 'zero matches is a clean no-op' );

echo "\nupdate-form: the form 52 trap\n";
$choice1 = 'Count me in!  I would like to bring a Basket/Raffle item to support this event!';
$form = array(
	'id' => 52, 'title' => 'PRODUCT Basket Pledge & Cash Donation', 'description' => 'Order Dinner, raffle tickets, pledges',
	'fields' => array(
		field( 19, 'checkbox', 'Basket Pledge/Cash Donation', array(
			'choices' => array(
				array( 'text' => $choice1, 'value' => $choice1, 'isSelected' => false, 'price' => '' ),
				array( 'text' => 'I would like to make a cash donation!', 'value' => 'I would like to make a cash donation!' ),
			),
			'inputs' => array( array( 'id' => '19.1', 'label' => $choice1 ), array( 'id' => '19.2', 'label' => 'I would like to make a cash donation!' ) ),
		) ),
		field( 20, 'list', 'Basket Gift Item(s)', array( 'conditionalLogic' => array( 'enabled' => true, 'rules' => array( array( 'fieldId' => '19', 'operator' => 'is', 'value' => $choice1 ) ) ) ) ),
		field( 25, 'radio', 'Are you able to pledge a raffle and/or auction item?' ),
		field( 32, 'list', 'Auction Item(s)', array( 'conditionalLogic' => array( 'enabled' => true, 'rules' => array( array( 'fieldId' => '25', 'operator' => 'is', 'value' => 'Yes' ) ) ) ) ),
	),
	'notifications' => array(
		'n1' => array( 'id' => 'n1', 'name' => 'Regional Specialty', 'to' => 'a@x.org', 'toType' => 'email', 'bcc' => 'old@gmail.com', 'message' => '{Total:13}' ),
		'n2' => array( 'id' => 'n2', 'name' => 'User Copy', 'to' => '2', 'toType' => 'field' ),
	),
	'confirmations' => array(),
);
$new_text = 'Count me in!  I would like to pledge a raffle or auction item to support this event!';
$r = FDJ_MCP_Annual_Reset::compute_form_changes( $form, array(
	'title' => 'PRODUCT Raffle/Auction/Cash Donation Pledge',
	'description' => 'Order raffle tickets, pledges',
	'fields' => array(
		array( 'id' => 19, 'label' => 'Raffle/Auction/Cash Donation Pledge', 'choices' => array( array( 'from' => $choice1, 'text' => $new_text ) ) ),
		array( 'id' => 20, 'label' => 'Raffle/Auction Item(s)' ),
		array( 'id' => 25, 'css_class' => 'gf_hidden' ),
	),
	'notifications' => array( array( 'id' => 'n1', 'bcc' => '' ) ),
) );
ok( ! $r['errors'], 'no errors on a normal edit' . ( $r['errors'] ? ': ' . implode( '; ', $r['errors'] ) : '' ) );
$f19 = $r['form']['fields'][0];
ok( $f19->choices[0]['text'] === $new_text && $f19->choices[0]['value'] === $new_text, 'choice text AND stored value both change' );
ok( $f19->inputs[0]['label'] === $new_text, 'the checkbox input label follows' );
ok( $r['form']['fields'][1]->conditionalLogic['rules'][0]['value'] === $new_text, 'the rule on field 20 now matches the new value, so the list field still shows' );
ok( $form['fields'][1]->conditionalLogic['rules'][0]['value'] === $choice1, 'the original form object was not modified (dry run safe)' );
ok( 'gf_hidden' === $r['form']['fields'][2]->cssClass, 'css_class hides a field' );
ok( '' === $r['form']['notifications']['n1']['bcc'], 'a notification BCC can be cleared' );
ok( 'PRODUCT Raffle/Auction/Cash Donation Pledge' === $r['form']['title'] && 'Order raffle tickets, pledges' === $r['form']['description'], 'title and description change' );
$kinds = array_column( $r['changes'], 'what' );
ok( in_array( 'conditional logic rules rewritten', $kinds, true ), 'the rule rewrite is reported in the changes' );

$r2 = FDJ_MCP_Annual_Reset::compute_form_changes( $form, array( 'fields' => array( array( 'id' => 25, 'delete' => true ) ) ) );
ok( 1 === count( $r2['errors'] ) && false !== strpos( $r2['errors'][0], 'conditional logic on field 32' ), 'deleting field 25 is refused while field 32 depends on it' );
$r3 = FDJ_MCP_Annual_Reset::compute_form_changes( $form, array( 'force_delete' => true, 'fields' => array( array( 'id' => 25, 'delete' => true ) ) ) );
ok( ! $r3['errors'] && 3 === count( $r3['form']['fields'] ), 'force_delete allows it' );
$r4 = FDJ_MCP_Annual_Reset::compute_form_changes( $form, array( 'fields' => array( array( 'id' => 32, 'delete' => true ) ) ) );
ok( ! $r4['errors'] && 3 === count( $r4['form']['fields'] ), 'a field nothing refers to deletes cleanly' );
$form_tag = $form;
$form_tag['notifications']['n1']['message'] = 'Pledge: {Auction Item(s):32}';
$r5 = FDJ_MCP_Annual_Reset::compute_form_changes( $form_tag, array( 'fields' => array( array( 'id' => 32, 'delete' => true ) ) ) );
ok( 1 === count( $r5['errors'] ) && false !== strpos( $r5['errors'][0], 'merge tag' ), 'a merge tag in a notification also blocks deletion' );
$r6 = FDJ_MCP_Annual_Reset::compute_form_changes( $form, array( 'notifications' => array( array( 'id' => 'n2', 'to' => 'x@y.org' ) ) ) );
ok( 1 === count( $r6['errors'] ) && false !== strpos( $r6['errors'][0], 'not a fixed address' ), 'a notification that sends to a field cannot be re-addressed' );
$r7 = FDJ_MCP_Annual_Reset::compute_form_changes( $form, array( 'fields' => array( array( 'id' => 99, 'label' => 'x' ) ) ) );
ok( 1 === count( $r7['errors'] ), 'an unknown field ID is an error, not a silent skip' );
$r8 = FDJ_MCP_Annual_Reset::compute_form_changes( $form, array( 'fields' => array( array( 'id' => 19, 'choices' => array( array( 'from' => 'no such choice', 'text' => 'x' ) ) ) ) ) );
ok( 1 === count( $r8['errors'] ), 'an unknown choice is an error' );
$r9 = FDJ_MCP_Annual_Reset::compute_form_changes( $form, array( 'title' => $form['title'] ) );
ok( ! $r9['errors'] && ! $r9['changes'], 'asking for what is already true changes nothing' );
$r10 = FDJ_MCP_Annual_Reset::compute_form_changes( $r['form'], array( 'fields' => array( array( 'id' => 19, 'choices' => array( array( 'from' => $new_text, 'text' => $new_text ) ) ) ) ) );
ok( ! $r10['errors'] && ! $r10['changes'], 're-running an applied choice edit is a no-op' );

echo "\nmenu_issues\n";
$items = array(
	array( 'id' => 1, 'parent' => 0, 'title' => 'About Parada dos Amigos', 'url' => '#', 'linked_status' => '' ),
	array( 'id' => 2, 'parent' => 0, 'title' => 'Shop', 'url' => '#', 'linked_status' => '' ),
	array( 'id' => 3, 'parent' => 2, 'title' => 'Parada dos Amigos', 'url' => 'https://x.org/product/parada/', 'linked_status' => 'publish' ),
	array( 'id' => 4, 'parent' => 0, 'title' => 'Pre-Order Raffle Tickets', 'url' => 'https://x.org/pre-order-raffle-tickets-2/', 'linked_status' => 'publish' ),
	array( 'id' => 5, 'parent' => 0, 'title' => 'Prepaid Catalog Order', 'url' => 'https://x.org/coming-soon/', 'linked_status' => '' ),
	array( 'id' => 6, 'parent' => 0, 'title' => 'Old page', 'url' => 'https://x.org/old/', 'linked_status' => 'draft' ),
	array( 'id' => 7, 'parent' => 0, 'title' => 'pre-order raffle tickets', 'url' => 'https://x.org/product/pre-order-raffle-tickets/', 'linked_status' => 'publish' ),
	array( 'id' => 8, 'parent' => 0, 'title' => 'Again', 'url' => 'https://x.org/product/parada', 'linked_status' => 'publish' ),
);
$iss = FDJ_MCP_Annual_Reset::menu_issues( $items );
$by  = array();
foreach ( $iss as $i ) { $by[ $i['item_id'] ][] = $i['issue']; }
ok( isset( $by[1] ) && false !== strpos( $by[1][0], 'nowhere' ), 'a "#" link with no children is flagged' );
ok( ! isset( $by[2] ), 'a "#" header that has children is fine' );
ok( isset( $by[4] ) && false !== strpos( $by[4][0], 'twin' ), 'a "-2" URL is flagged as a probable twin' );
ok( isset( $by[5] ) && false !== strpos( $by[5][0], 'coming soon' ), 'a coming-soon link is flagged' );
ok( isset( $by[6] ) && false !== strpos( $by[6][0], 'draft' ), 'a link to a draft is flagged' );
ok( isset( $by[7] ) && false !== strpos( implode( ' ', $by[7] ), 'same title as item 4' ), 'a duplicate title is flagged (case insensitive)' );
ok( isset( $by[8] ) && false !== strpos( implode( ' ', $by[8] ), 'same link as item 3' ), 'a duplicate link is flagged (trailing slash ignored)' );

$hid = FDJ_MCP_Annual_Reset::menu_issues( array(
	array( 'id' => 1, 'parent' => 0, 'title' => 'Prepaid Catalog Order', 'url' => 'https://x.org/coming-soon/', 'linked_status' => '', 'classes' => array( 'hide_link' ) ),
	array( 'id' => 2, 'parent' => 0, 'title' => 'Prepaid Catalog Order', 'url' => 'https://x.org/coming-soon/', 'linked_status' => '', 'classes' => array() ),
) );
ok( 1 === count( array_unique( array_column( $hid, 'item_id' ) ) ) && 2 === (int) $hid[0]['item_id'], 'an item with a hide_ class is skipped, an identical visible one is still reported' );

echo "\nproduct_issues\n";
$p = function ( $o ) { return array_merge( array( 'id' => 1, 'title' => 'T', 'manage_stock' => true, 'stock_quantity' => 1, 'stock_status' => 'instock' ), $o ); };
ok( array() === FDJ_MCP_Annual_Reset::product_issues( $p( array() ), 1, array() ), 'managed, qty 1, in stock: clean' );
ok( 1 === count( FDJ_MCP_Annual_Reset::product_issues( $p( array( 'manage_stock' => false ) ), 1, array() ) ), 'unmanaged stock is flagged' );
ok( 2 === count( FDJ_MCP_Annual_Reset::product_issues( $p( array( 'stock_quantity' => 0, 'stock_status' => 'outofstock' ) ), 1, array() ) ), 'a sold-out product shows both problems' );
ok( array() === FDJ_MCP_Annual_Reset::product_issues( $p( array( 'stock_quantity' => 3 ) ), 1, array( 1 => 3 ) ), 'an exception quantity is honored' );
ok( array() === FDJ_MCP_Annual_Reset::product_issues( $p( array( 'manage_stock' => false ) ), 1, array( '1' => 'unlimited' ) ), 'unlimited exception accepts unmanaged stock' );
ok( 1 === count( FDJ_MCP_Annual_Reset::product_issues( $p( array() ), 1, array( 1 => 'unlimited' ) ) ), 'unlimited exception flags managed stock' );
ok( 1 === count( FDJ_MCP_Annual_Reset::product_issues( $p( array( 'mentions' => false ) ), 1, array() ) ), 'a missing deadline mention is flagged' );
ok( array() === FDJ_MCP_Annual_Reset::product_issues( $p( array( 'mentions' => null ) ), 1, array() ), 'no deadline check requested: nothing flagged' );

echo "\ncsv_safe\n";
ok( "'=SUM(A1)" === FDJ_MCP_Annual_Reset::csv_safe( '=SUM(A1)' ), 'formula is neutralised' );
ok( "'@cmd" === FDJ_MCP_Annual_Reset::csv_safe( '@cmd' ), '@ is neutralised' );
ok( '-12.50' === FDJ_MCP_Annual_Reset::csv_safe( '-12.50' ), 'a negative number is left alone' );
ok( '+15305551234' === FDJ_MCP_Annual_Reset::csv_safe( '+15305551234' ), 'a phone number is left alone' );
ok( 'Fido' === FDJ_MCP_Annual_Reset::csv_safe( 'Fido' ), 'ordinary text is left alone' );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
