<?php
/**
 * Data the form-event feature matches against: the Meta standard event catalogue, the
 * form-title patterns and the identifier-name patterns.
 *
 * Kept as data in one place so a new word or a new standard event is one edit, and the
 * matching rules in detection.php never change for it.
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Meta Conversions API standard events, in Meta's own casing. Matching is case-sensitive:
 * Meta treats `completeregistration` as a custom event. Read by the option sanitizer and,
 * through the forms read route, by the settings dropdown.
 */
const PIXELFLOW_META_STANDARD_EVENTS = [
    'AddPaymentInfo',
    'AddToCart',
    'AddToWishlist',
    'CompleteRegistration',
    'Contact',
    'CustomizeProduct',
    'Donate',
    'FindLocation',
    'InitiateCheckout',
    'Lead',
    'PageView',
    'Purchase',
    'Schedule',
    'Search',
    'StartTrial',
    'SubmitApplication',
    'Subscribe',
    'ViewContent',
];

/**
 * Lowercased substrings that mark a form title as not a conversion: search, login, password
 * and comment forms, in English, French, German, Spanish, Italian and Dutch.
 */
const PIXELFLOW_FORM_TITLE_EXCLUSION_PATTERNS = [
    'search',
    'find',
    'login',
    'log in',
    'sign in',
    'signin',
    'password',
    'comment',
    'reply',
    'recherche',
    'suche',
    'buscar',
    'cerca',
    'zoeken',
    'connexion',
    'anmeldung',
    'iniciar sesión',
    'accedi',
    'mot de passe',
    'passwort',
    'contraseña',
    'commentaire',
    'kommentar',
    'comentario',
    'commento',
    'reactie',
];

/**
 * Lowercased substrings that mark a form title as a registration: newsletter, subscribe and
 * signup forms, in the same six languages.
 */
const PIXELFLOW_FORM_TITLE_REGISTRATION_PATTERNS = [
    'newsletter',
    'subscribe',
    'signup',
    'sign up',
    'register',
    'registration',
    'abonnement',
    'abonnieren',
    'infolettre',
    'boletín',
    'suscribir',
    'iscriviti',
    'registrieren',
    'registro',
    'inschrijven',
];

/**
 * Identifiers a form event can carry in customerData, in the order the settings page shows them.
 */
const PIXELFLOW_FORM_IDENTIFIERS = ['em', 'ph', 'fn', 'ln', 'ct', 'st', 'zp', 'country'];

/**
 * Pattern groups whose words also start other people's data: `name` in `Company name`, `state`
 * in `Statement`. A field there is sent only when a pattern is its whole key or label; a field
 * that merely contains one is offered to a person and sent once they choose it.
 */
const PIXELFLOW_FORM_WHOLE_TEXT_PATTERN_GROUPS = ['name', 'st'];

/**
 * Words that name an identifier in a plain text field's key or label, in English, French,
 * German, Spanish, Italian and Dutch.
 *
 * A pattern matches where a word starts, so `telefon` finds `Telefonnummer` while `tel` would
 * not be safe to list at all (it starts no word in `hotel`, but it does in `telling`). Short,
 * ambiguous words are left out on purpose: a missed field waits for a person, a wrong one
 * sends a stranger's data.
 *
 * `name` lists words for a single field holding the whole name, which is split into first and
 * last name at submit time.
 */
const PIXELFLOW_FORM_IDENTIFIER_PATTERNS = [
    'em'      => [
        'email',
        'e mail',
        'mail address',
        'mailadres',
        'courriel',
        'correo',
        'posta elettronica',
    ],
    'ph'      => [
        'phone',
        'telephone',
        'mobile',
        'cellphone',
        'landline',
        'whatsapp',
        'téléphone',
        'telefon',
        'handy',
        'mobilnummer',
        'teléfono',
        'telefono',
        'móvil',
        'movil',
        'celular',
        'cellulare',
        'telefoon',
        'mobiel',
        'gsm',
    ],
    'fn'      => [
        'first name',
        'firstname',
        'fname',
        'given name',
        'forename',
        'prénom',
        'prenom',
        'vorname',
        'primer nombre',
        'voornaam',
    ],
    'ln'      => [
        'last name',
        'lastname',
        'lname',
        'surname',
        'family name',
        'nom de famille',
        'nachname',
        'familienname',
        'apellido',
        'cognome',
        'achternaam',
    ],
    'name'    => [
        'full name',
        'fullname',
        'your name',
        'name',
        'nom',
        'vollständiger name',
        'nombre',
        'nome',
        'naam',
    ],
    'ct'      => [
        'city',
        'town',
        'ville',
        'stadt',
        'wohnort',
        'ciudad',
        'città',
        'citta',
        'woonplaats',
        'plaats',
    ],
    'st'      => [
        'state',
        'province',
        'region',
        'county',
        'état',
        'bundesland',
        'provincia',
        'estado',
        'regione',
        'provincie',
    ],
    'zp'      => [
        'zip',
        'postcode',
        'post code',
        'postal',
        'code postal',
        'postleitzahl',
        'plz',
        'código postal',
        'codigo postal',
        'codice postale',
    ],
    'country' => [
        'country',
        'pays',
        'país',
        'pais',
        'paese',
        'nazione',
    ],
];

/**
 * Canonical field types an adapter reports. The adapter maps its plugin's own vocabulary onto
 * these, so detection and the settings page speak one language.
 *
 * - Native identifier types: email, phone, first_name, last_name, name (one field holding the
 *   whole name), city, state, zip, country.
 * - Visitor input that carries no identifier by type: text (single line), textarea, choice,
 *   number, date, file, other.
 * - Non-input types, which carry no visitor input and are not counted when deciding whether a
 *   form is email-only: hidden, submit, captcha, consent, layout.
 */
const PIXELFLOW_FORM_NON_INPUT_TYPES = ['hidden', 'submit', 'captcha', 'consent', 'layout'];

/** Canonical type → identifier for the native-type detection stage. */
const PIXELFLOW_FORM_NATIVE_TYPE_IDENTIFIERS = [
    'email'      => 'em',
    'phone'      => 'ph',
    'first_name' => 'fn',
    'last_name'  => 'ln',
    'city'       => 'ct',
    'state'      => 'st',
    'zip'        => 'zp',
    'country'    => 'country',
];
