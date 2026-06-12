<?php

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sends customer emails in the order language and admin emails in the
 * default site language.
 *
 * WC_Email::trigger() calls setup_locale() BEFORE assigning the order to
 * $email->object, so the locale-switch filters fire too early to know the
 * target language. Instead the switch happens in the per-email
 * woocommerce_email_recipient_{id} filter — by then the object is assigned
 * and neither subject nor content have been rendered. The switch is restored
 * in woocommerce_allow_restoring_email_locale, which fires at the end of
 * every trigger() regardless of whether the email was sent.
 *
 * Default subjects/headings translate via the WC .mo files; custom subjects
 * entered in WC settings are used verbatim for all languages.
 */
class WP_LOC_WC_Emails {

    private $previous_lang = null;

    public function __construct() {
        add_filter( 'woocommerce_email_classes', [ $this, 'register_recipient_filters' ], 999 );
        add_filter( 'woocommerce_allow_restoring_email_locale', [ $this, 'restore_email_language' ], 10, 2 );
    }

    public function register_recipient_filters( $emails ) {
        foreach ( $emails as $email ) {
            add_filter( "woocommerce_email_recipient_{$email->id}", [ $this, 'switch_email_language' ], 1, 3 );
        }

        return $emails;
    }

    /**
     * Pass-through filter used only for its side effect: switching the
     * language before the email subject/content are rendered.
     */
    public function switch_email_language( $recipient, $object = null, $email = null ) {
        // No order context (e.g. the WC settings email list) — never switch.
        if ( ! $object instanceof WC_Order || ! $email ) {
            return $recipient;
        }

        $target = $email->is_customer_email()
            ? WP_LOC_WC_Order_Language::get( $object )
            : WP_LOC_WC_Lang::default_lang();

        $current = WP_LOC_WC_Lang::current_lang();

        if ( $target !== $current && $this->previous_lang === null ) {
            $this->previous_lang = $current;
            WP_LOC_Routing::switch_language( $target );
        }

        return $recipient;
    }

    public function restore_email_language( $allow, $email ) {
        if ( $this->previous_lang !== null ) {
            WP_LOC_Routing::switch_language( $this->previous_lang );
            $this->previous_lang = null;
        }

        return $allow;
    }
}
