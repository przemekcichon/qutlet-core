<?php
/**
 * Testy jednostkowe czystych funkcji OrderSync\OrderStatuses (P-6.5b).
 *
 * @package Qutlet\Core
 */

declare( strict_types=1 );

namespace Qutlet\Core\Tests\OrderSync;

use Qutlet\Core\OrderSync\OrderStatuses;
use PHPUnit\Framework\TestCase;

/**
 * Charakteryzuje rejestrację statusu `wc-shipped` na poziomie czystych funkcji
 * (bez WordPressa — filtry Woo tylko cienko owijają te helpery WP-ową etykietą).
 *
 * Dowodzimy trzech rzeczy trudnych do zauważenia w review:
 * 1. literał statusu = kontrakt §12.5 (`wc-shipped`) + spójność prefiksowanego i
 *    nieprefiksowanego wariantu (nie mogą się rozjechać);
 * 2. `wc-shipped` ląduje MIĘDZY `wc-processing` a `wc-completed` na liście statusów;
 * 3. lista „opłaconych" dostaje slug BEZ prefiksu (`shipped`), a NIE `wc-shipped`
 *    — pułapka: `wc_get_is_paid_statuses()` trzyma slugi bez `wc-`, `wc_order_statuses`
 *    z prefiksem.
 */
final class OrderStatusesTest extends TestCase {

	/**
	 * Domyślne statusy Woo w kształcie `[slug => etykieta]` (klucze z prefiksem
	 * `wc-`, VERBATIM z `wc_get_order_statuses()`, Woo 10.9.4).
	 *
	 * @return array<string,string>
	 */
	private function default_order_statuses(): array {
		return array(
			'wc-pending'    => 'Pending payment',
			'wc-processing' => 'Processing',
			'wc-on-hold'    => 'On hold',
			'wc-completed'  => 'Completed',
			'wc-cancelled'  => 'Cancelled',
			'wc-refunded'   => 'Refunded',
			'wc-failed'     => 'Failed',
		);
	}

	public function test_status_literals_match_contract_and_are_consistent(): void {
		// Regresja na literał z kontraktu §12.5 (case-sensitive).
		$this->assertSame( 'wc-shipped', OrderStatuses::STATUS );
		$this->assertSame( 'shipped', OrderStatuses::STATUS_UNPREFIXED );

		// Warianty nie mogą się rozjechać: nieprefiksowany = prefiksowany bez `wc-`.
		$this->assertSame( 'wc-' . OrderStatuses::STATUS_UNPREFIXED, OrderStatuses::STATUS );
	}

	public function test_insert_after_places_key_between_processing_and_completed(): void {
		$result = OrderStatuses::insert_after(
			$this->default_order_statuses(),
			'wc-processing',
			OrderStatuses::STATUS,
			'Wysłane'
		);

		$keys      = array_keys( $result );
		$shipped   = array_search( OrderStatuses::STATUS, $keys, true );
		$processing = array_search( 'wc-processing', $keys, true );
		$completed = array_search( 'wc-completed', $keys, true );

		$this->assertNotFalse( $shipped );
		// Klucz PREFIKSOWANY, zaraz za `wc-processing`, przed `wc-completed`.
		$this->assertSame( $processing + 1, $shipped );
		$this->assertLessThan( $completed, $shipped );
		$this->assertSame( 'Wysłane', $result[ OrderStatuses::STATUS ] );
	}

	public function test_insert_after_preserves_other_entries(): void {
		$original = $this->default_order_statuses();
		$result   = OrderStatuses::insert_after( $original, 'wc-processing', OrderStatuses::STATUS, 'Wysłane' );

		// Wszystkie oryginalne pary nienaruszone (dodaliśmy dokładnie jedną).
		$this->assertCount( count( $original ) + 1, $result );
		foreach ( $original as $key => $value ) {
			$this->assertSame( $value, $result[ $key ] );
		}
	}

	public function test_insert_after_appends_when_anchor_missing(): void {
		$list   = array( 'a' => 1, 'b' => 2 );
		$result = OrderStatuses::insert_after( $list, 'nie-ma-takiego', 'c', 3 );

		// Fallback: brak kotwicy → doklejenie na końcu, kolejność zachowana.
		$this->assertSame( array( 'a', 'b', 'c' ), array_keys( $result ) );
		$this->assertSame( 3, $result['c'] );
	}

	public function test_add_to_paid_statuses_appends_unprefixed_slug(): void {
		// `wc_get_is_paid_statuses()` zwraca slugi BEZ prefiksu `wc-`.
		$paid   = OrderStatuses::add_to_paid_statuses( array( 'processing', 'completed' ) );

		$this->assertContains( 'shipped', $paid );
		// Krytyczne: NIE prefiksowany wariant na tej liście.
		$this->assertNotContains( 'wc-shipped', $paid );
		$this->assertSame( array( 'processing', 'completed', 'shipped' ), $paid );
	}

	public function test_add_to_paid_statuses_is_idempotent(): void {
		$paid = OrderStatuses::add_to_paid_statuses( array( 'processing', 'completed', 'shipped' ) );

		// Brak duplikatu, gdy status już obecny.
		$this->assertSame( array( 'processing', 'completed', 'shipped' ), $paid );
	}

	public function test_add_to_paid_statuses_tolerates_non_array(): void {
		$paid = OrderStatuses::add_to_paid_statuses( null );

		$this->assertSame( array( 'shipped' ), $paid );
	}

	public function test_append_unique_appends_and_dedupes(): void {
		$this->assertSame( array( 'x', 'y' ), OrderStatuses::append_unique( array( 'x' ), 'y' ) );
		$this->assertSame( array( 'x' ), OrderStatuses::append_unique( array( 'x' ), 'x' ) );
	}
}
