<?php
/**
 * Contact Form 7 adapter.
 *
 * Forms are `wpcf7_contact_form` posts; CF7 deletes them for good rather than trashing them, so
 * WordPress's own `post_status => 'any'` is the existence rule. Fields are form tags keyed by
 * their name. `wpcf7_submit` reports every attempt with a status: mail_sent and mail_failed are
 * a completed form (a broken SMTP is the site's problem), everything else — spam, validation
 * failure, aborted — sends nothing.
 *
 * @package PixelFlow
 */

// Prevent direct access
if ( ! defined('ABSPATH')) {
    exit;
}

/**
 * Contact Form 7 adapter.
 */
class PixelFlow_Form_Adapter_CF7 extends PixelFlow_Form_Adapter
{
    /** CF7 base types → canonical types. Anything unlisted is `other`. */
    private const TYPES = [
        'email'      => 'email',
        'tel'        => 'phone',
        'text'       => 'text',
        'textarea'   => 'textarea',
        'number'     => 'number',
        'range'      => 'number',
        'date'       => 'date',
        'checkbox'   => 'choice',
        'radio'      => 'choice',
        'select'     => 'choice',
        'file'       => 'file',
        'acceptance' => 'consent',
        'hidden'     => 'hidden',
        'quiz'       => 'captcha',
        'captchac'   => 'captcha',
        'captchar'   => 'captcha',
        'submit'     => 'submit',
    ];

    /** Statuses that mean the visitor completed the form. */
    private const SENT_STATUSES = ['mail_sent', 'mail_failed'];

    public function id(): string
    {
        return 'cf7';
    }

    public function label(): string
    {
        return 'Contact Form 7';
    }

    public function is_active(): bool
    {
        return class_exists('WPCF7_ContactForm');
    }

    public function register_hooks(): void
    {
        add_action('wpcf7_submit', [$this, 'on_submit'], 10, 2);
    }

    public function list_forms(): array
    {
        $forms = [];
        foreach (WPCF7_ContactForm::find(['post_status' => 'any']) as $form) {
            $forms[] = [
                'form_id' => (string) $form->id(),
                'title'   => (string) $form->title(),
                'fields'  => $this->fields_of($form),
            ];
        }

        return $forms;
    }

    public function form_exists(string $form_id): bool
    {
        $post = get_post((int) $form_id);

        return $post !== null
            && $post->post_type === 'wpcf7_contact_form'
            && ! in_array($post->post_status, ['trash', 'auto-draft'], true);
    }

    /**
     * @param WPCF7_ContactForm $contact_form Submitted form
     * @param array             $result       Submission result with `status`
     * @return void
     */
    public function on_submit($contact_form, $result): void
    {
        $status = is_array($result) && isset($result['status']) ? (string) $result['status'] : '';
        if ( ! in_array($status, self::SENT_STATUSES, true) || ! class_exists('WPCF7_Submission')) {
            return;
        }

        $submission = WPCF7_Submission::get_instance();
        $posted     = $submission ? $submission->get_posted_data() : [];

        $this->submit(
            (string) $contact_form->id(),
            (string) $contact_form->title(),
            $this->with_values($this->fields_of($contact_form), is_array($posted) ? $posted : []),
            'wpcf7_submit'
        );
    }

    /**
     * @param WPCF7_ContactForm $form Form
     * @return array
     */
    private function fields_of($form): array
    {
        $fields = [];
        foreach ($form->scan_form_tags() as $tag) {
            $name = isset($tag->name) ? (string) $tag->name : '';
            if ($name === '') {
                continue;
            }
            $basetype = isset($tag->basetype) ? (string) $tag->basetype : '';
            $label    = $name;
            if (method_exists($tag, 'has_option') && $tag->has_option('placeholder') && ! empty($tag->values[0])) {
                $label = (string) $tag->values[0];
            }
            $fields[] = [
                'key'   => $name,
                'type'  => self::TYPES[$basetype] ?? 'other',
                'label' => $label,
            ];
        }

        return $fields;
    }
}
