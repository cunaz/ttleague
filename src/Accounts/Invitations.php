<?php
/**
 * Einladungen: Konto anlegen und Anmeldelink mit längerer Gültigkeit senden.
 *
 * @package TTLeague
 */

declare(strict_types=1);

namespace TTLeague\Accounts;

use TTLeague\Infrastructure\AuditLog;
use TTLeague\Infrastructure\Mail\Mailer;
use TTLeague\Infrastructure\Mail\Message;

final class Invitations {

	public function __construct(
		private readonly AccountService $accounts,
		private readonly MagicLinks $links,
		private readonly Mailer $mailer,
		private readonly AuditLog $audit,
	) {}

	/**
	 * @throws \RuntimeException Wenn die Person keine gültige E-Mail-Adresse hat.
	 */
	public function invite( int $person_id, int $by_user_id ): void {
		$person = $this->accounts->person( $person_id );
		if ( null === $person ) {
			throw new \RuntimeException( esc_html__( 'Person nicht gefunden.', 'ttleague' ) );
		}
		$this->accounts->ensure_user( $person_id );
		$token   = $this->links->create( $person_id, MagicLinks::INVITE_TTL );
		$url     = LoginFlow::link_url( $token );
		$site    = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$inviter = get_userdata( $by_user_id );
		$by      = false === $inviter ? __( 'Die Technische Kommission', 'ttleague' ) : $inviter->display_name;
		$first   = AccountService::salutation( $person );

		/* translators: 1: Vorname, 2: einladende Person, 3: Name der Seite, 4: Link, 5: Adresse der Anmeldeseite */
		$text = sprintf( __( "Hallo %1\$s\n\n%2\$s hat dich zu %3\$s eingeladen. Dort trägst du Resultate nach, bestätigst sie und siehst deine Spiele.\n\nMit diesem Link meldest du dich an. Er ist 7 Tage gültig und funktioniert einmal:\n%4\$s\n\nEin Passwort brauchst du nicht: Später forderst du auf %5\$s einfach einen neuen Link an.", 'ttleague' ), $first, $by, $site, $url, LoginFlow::page_url() );
		$html = '<p>' . esc_html( sprintf( /* translators: %s: Vorname */ __( 'Hallo %s', 'ttleague' ), $first ) ) . '</p>'
			. '<p>' . esc_html( sprintf( /* translators: 1: einladende Person, 2: Name der Seite */ __( '%1$s hat dich zu %2$s eingeladen. Dort trägst du Resultate nach, bestätigst sie und siehst deine Spiele.', 'ttleague' ), $by, $site ) ) . '</p>'
			. Mailer::button( $url, __( 'Jetzt anmelden', 'ttleague' ) )
			. '<p>' . esc_html__( 'Der Link ist 7 Tage gültig und funktioniert einmal. Ein Passwort brauchst du nicht: Später forderst du auf der Anmeldeseite einfach einen neuen Link an.', 'ttleague' ) . '</p>';

		$this->mailer->send(
			new Message(
				(string) $person['email'],
				/* translators: %s: Name der Seite */
				sprintf( __( 'Einladung zu %s', 'ttleague' ), $site ),
				$text,
				$html,
				'invitation'
			)
		);
		$this->audit->record( 'person', $person_id, 'invited', $by_user_id );
	}
}
