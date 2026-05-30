<?php
/**
 * Plugin Name:       The SEO Playbook
 * Plugin URI:        https://ameliahollis.net/the-seo-playbook
 * Description:       A powerful, modular SEO suite for WordPress. Includes Post SEO + Social Cards, a Person/Author entity with an auto-built profile page, FAQ schema, canonical URLs, and enhanced llms.txt for AI search visibility.
 * Version:           2.2.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Author:            Amelia Hollis
 * Text Domain:       the-seo-playbook
 * License:           GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'TSEP_VERSION', '2.2.0' );

class The_SEO_Playbook {

    private static $instance = null;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action( 'plugins_loaded', [ $this, 'init' ] );
        add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), [ $this, 'add_settings_link' ] );

        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );
    }

    public function init() {
        $this->init_schema();
        $this->init_person_schema();           // ← NEW: Person / Author entity + profile page
        $this->init_local_seo();
        $this->init_sitemaps();
        $this->init_google_news_sitemap();
        $this->init_onpage_analysis();
        $this->init_breadcrumbs();
        $this->init_faq_schema();              // ← NEW: per-post FAQPage schema
        $this->init_canonical();               // ← NEW: canonical URL output
        $this->init_llms_txt();
        $this->init_robots_txt();
        $this->init_webmaster_tools();
        $this->init_indexnow();
        $this->init_post_seo();                // Post SEO + Social Cards
        $this->init_updater();                 // GitHub auto-updater

        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'wp_head', [ $this, 'post_seo_output_meta_tags' ], 5 ); // Early output
    }

    public function add_settings_link( $links ) {
        $settings_link = '<a href="' . admin_url( 'options-general.php?page=the-seo-playbook' ) . '">' . __( 'Settings', 'the-seo-playbook' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /* ==========================================================================
     * SETTINGS UI (Tabbed Interface)
     * ========================================================================== */
    public function add_settings_page() {
        add_options_page(
            __( 'The SEO Playbook', 'the-seo-playbook' ),
            __( 'The SEO Playbook', 'the-seo-playbook' ),
            'manage_options',
            'the-seo-playbook',
            [ $this, 'render_settings_page' ]
        );
    }

    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'schema';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <h2 class="nav-tab-wrapper">
                <a href="?page=the-seo-playbook&tab=schema" class="nav-tab <?php echo $active_tab == 'schema' ? 'nav-tab-active' : ''; ?>">Schema &amp; Entity</a>
                <a href="?page=the-seo-playbook&tab=sitemaps" class="nav-tab <?php echo $active_tab == 'sitemaps' ? 'nav-tab-active' : ''; ?>">Sitemaps</a>
                <a href="?page=the-seo-playbook&tab=onpage" class="nav-tab <?php echo $active_tab == 'onpage' ? 'nav-tab-active' : ''; ?>">On-Page</a>
                <a href="?page=the-seo-playbook&tab=post-seo" class="nav-tab <?php echo $active_tab == 'post-seo' ? 'nav-tab-active' : ''; ?>">Post SEO &amp; Social Cards</a>
                <a href="?page=the-seo-playbook&tab=advanced" class="nav-tab <?php echo $active_tab == 'advanced' ? 'nav-tab-active' : ''; ?>">Advanced tools</a>
            </h2>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'tsep_options_' . $active_tab );
                do_action( 'tsep_render_tab_' . $active_tab );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function activate() {
        $this->llms_add_rewrite_rule();
        $this->robots_add_rewrite_rule();
        $this->sitemap_add_rewrite_rule();
        $this->news_sitemap_add_rewrite_rule();
        $this->person_maybe_create_page();
        flush_rewrite_rules();
    }
    public function deactivate() { flush_rewrite_rules(); }

    /* ==========================================================================
     * NEW MODULE: Person / Author Entity + auto-built profile page
     * ========================================================================== */
    private function init_person_schema() {
        add_action( 'admin_init', [ $this, 'person_register_settings' ] );
        add_action( 'admin_init', [ $this, 'person_maybe_create_page' ] );
        add_action( 'tsep_render_tab_schema', [ $this, 'person_render_settings' ] );
        add_action( 'wp_head', [ $this, 'person_output_schema' ], 6 );
        add_shortcode( 'tsep_person', [ $this, 'person_shortcode' ] );
    }

    public function person_register_settings() {
        register_setting( 'tsep_options_schema', 'tsep_person_enabled', 'absint' );
        register_setting( 'tsep_options_schema', 'tsep_person_name', 'sanitize_text_field' );
        register_setting( 'tsep_options_schema', 'tsep_person_job_title', 'sanitize_text_field' );
        register_setting( 'tsep_options_schema', 'tsep_person_bio', 'sanitize_textarea_field' );
        register_setting( 'tsep_options_schema', 'tsep_person_image', 'esc_url_raw' );
        register_setting( 'tsep_options_schema', 'tsep_person_same_as', [ $this, 'sanitize_url_list' ] );
    }

    public function person_render_settings() {
        $enabled  = get_option( 'tsep_person_enabled', 1 );
        $name     = get_option( 'tsep_person_name', '' );
        $job      = get_option( 'tsep_person_job_title', '' );
        $bio      = get_option( 'tsep_person_bio', '' );
        $image    = get_option( 'tsep_person_image', '' );
        $same_as  = (array) get_option( 'tsep_person_same_as', [] );
        $page_id  = (int) get_option( 'tsep_person_page_id', 0 );
        ?>
        <hr>
        <h2>Person / Author Entity</h2>
        <p>Builds a <code>Person</code> knowledge-graph entity so AI search engines (ChatGPT, Perplexity, Google SGE) can identify who you are. The plugin auto-creates an editable <strong>profile page</strong> that serves as your canonical entity URL.</p>
        <table class="form-table">
            <tr><th>Enable Person Entity</th><td><input type="checkbox" name="tsep_person_enabled" value="1" <?php checked( 1, $enabled ); ?>></td></tr>
            <tr><th>Name</th><td><input type="text" name="tsep_person_name" value="<?php echo esc_attr( $name ); ?>" class="regular-text" placeholder="e.g. Amelia Hollis"></td></tr>
            <tr><th>Job Title</th><td><input type="text" name="tsep_person_job_title" value="<?php echo esc_attr( $job ); ?>" class="regular-text" placeholder="e.g. Founder &amp; SEO Consultant"></td></tr>
            <tr><th>Bio / Description</th><td><textarea name="tsep_person_bio" rows="4" class="large-text"><?php echo esc_textarea( $bio ); ?></textarea></td></tr>
            <tr><th>Profile Image URL</th><td><input type="url" name="tsep_person_image" value="<?php echo esc_attr( $image ); ?>" class="widefat" placeholder="https://..."></td></tr>
            <tr>
                <th>Profile &amp; Social Links (sameAs)</th>
                <td>
                    <textarea name="tsep_person_same_as" rows="6" class="large-text code" placeholder="https://www.linkedin.com/in/you&#10;https://x.com/you&#10;https://github.com/you"><?php echo esc_textarea( implode( "\n", $same_as ) ); ?></textarea>
                    <p class="description">One URL per line. The single most important signal for AI entity resolution &mdash; link every profile that represents you (LinkedIn, X, GitHub, Wikipedia, Crunchbase, etc.).</p>
                </td>
            </tr>
        </table>
        <?php if ( $page_id && get_post_status( $page_id ) && get_post_status( $page_id ) !== 'trash' ) : ?>
            <p><strong>Your profile page:</strong>
                <a href="<?php echo esc_url( get_permalink( $page_id ) ); ?>" target="_blank"><?php echo esc_html( get_permalink( $page_id ) ); ?></a>
                &nbsp;(<a href="<?php echo esc_url( get_edit_post_link( $page_id ) ); ?>">edit</a>)
            </p>
            <p class="description">The page renders your bio via the <code>[tsep_person]</code> shortcode (always live from the fields above) and outputs the <code>Person</code> JSON-LD. Edits you make in the block editor are never overwritten.</p>
        <?php endif; ?>
        <hr>
        <?php
    }

    /**
     * Create the profile page exactly once. The page id is recorded the first time, so the plugin
     * never recreates it (respecting a deliberate trash/delete) and never overwrites its content
     * (so manual block-editor edits are safe). Runs on activation and defensively on admin_init.
     */
    public function person_maybe_create_page() {
        if ( ! get_option( 'tsep_person_enabled', 1 ) ) return;

        // Already created once (even if since trashed/deleted) — leave it alone.
        if ( false !== get_option( 'tsep_person_page_id', false ) ) return;

        $name  = get_option( 'tsep_person_name', '' );
        $title = $name ? 'About ' . $name : 'About Me';

        $new_id = wp_insert_post( [
            'post_title'   => $title,
            'post_name'    => 'about-me',
            'post_content' => '[tsep_person]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ] );

        if ( $new_id && ! is_wp_error( $new_id ) ) {
            update_option( 'tsep_person_page_id', $new_id );
        }
    }

    public function person_shortcode() {
        if ( ! get_option( 'tsep_person_enabled', 1 ) ) return '';
        $name = get_option( 'tsep_person_name', '' );
        if ( ! $name ) return '';

        $job   = get_option( 'tsep_person_job_title', '' );
        $bio   = get_option( 'tsep_person_bio', '' );
        $image = get_option( 'tsep_person_image', '' );
        $links = (array) get_option( 'tsep_person_same_as', [] );

        ob_start();
        ?>
        <div class="tsep-person" itemscope itemtype="https://schema.org/Person">
            <?php if ( $image ) : ?>
                <img class="tsep-person__image" src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $name ); ?>" itemprop="image" style="max-width:180px;height:auto;border-radius:50%;">
            <?php endif; ?>
            <h2 class="tsep-person__name" itemprop="name"><?php echo esc_html( $name ); ?></h2>
            <?php if ( $job ) : ?>
                <p class="tsep-person__title" itemprop="jobTitle"><?php echo esc_html( $job ); ?></p>
            <?php endif; ?>
            <?php if ( $bio ) : ?>
                <div class="tsep-person__bio" itemprop="description"><?php echo wpautop( esc_html( $bio ) ); ?></div>
            <?php endif; ?>
            <?php if ( $links ) : ?>
                <ul class="tsep-person__links">
                    <?php foreach ( $links as $url ) : ?>
                        <li><a href="<?php echo esc_url( $url ); ?>" rel="me noopener" itemprop="sameAs"><?php echo esc_html( $this->person_link_label( $url ) ); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function person_output_schema() {
        if ( ! get_option( 'tsep_person_enabled', 1 ) ) return;
        $name = get_option( 'tsep_person_name', '' );
        if ( ! $name ) return;

        $page_id = (int) get_option( 'tsep_person_page_id', 0 );

        // Output the full node only on the front page and the profile page to avoid sitewide bloat.
        $on_profile = $page_id && is_page( $page_id );
        if ( ! is_front_page() && ! $on_profile ) return;

        $person_url = $page_id ? get_permalink( $page_id ) : home_url( '/' );

        $person = [
            '@context'         => 'https://schema.org',
            '@type'            => 'Person',
            '@id'              => home_url( '/#person' ),
            'name'             => $name,
            'url'              => $person_url,
            'mainEntityOfPage' => $person_url,
        ];

        if ( $job = get_option( 'tsep_person_job_title', '' ) ) {
            $person['jobTitle'] = $job;
        }
        if ( $bio = get_option( 'tsep_person_bio', '' ) ) {
            $person['description'] = $bio;
        }
        if ( $image = get_option( 'tsep_person_image', '' ) ) {
            $person['image'] = $image;
        }
        $same_as = array_values( (array) get_option( 'tsep_person_same_as', [] ) );
        if ( $same_as ) {
            $person['sameAs'] = $same_as;
        }
        if ( get_option( 'tsep_schema_enabled', 1 ) ) {
            $person['worksFor'] = [ '@id' => home_url( '/#organization' ) ];
        }

        echo '<script type="application/ld+json">' . wp_json_encode( $person ) . '</script>' . "\n";
    }

    private function person_link_label( $url ) {
        $host = parse_url( $url, PHP_URL_HOST );
        if ( ! $host ) return $url;
        $host = preg_replace( '/^www\./', '', strtolower( $host ) );
        $map  = [
            'linkedin.com'       => 'LinkedIn',
            'twitter.com'        => 'Twitter / X',
            'x.com'              => 'X',
            'github.com'         => 'GitHub',
            'facebook.com'       => 'Facebook',
            'instagram.com'      => 'Instagram',
            'youtube.com'        => 'YouTube',
            'medium.com'         => 'Medium',
            'crunchbase.com'     => 'Crunchbase',
            'wikipedia.org'      => 'Wikipedia',
            'mastodon.social'    => 'Mastodon',
            'threads.net'        => 'Threads',
            'tiktok.com'         => 'TikTok',
            'scholar.google.com' => 'Google Scholar',
        ];
        foreach ( $map as $domain => $label ) {
            if ( $host === $domain || str_ends_with( $host, '.' . $domain ) ) return $label;
        }
        return ucfirst( explode( '.', $host )[0] );
    }

    /** Shared sanitizer: newline-separated (or array) list of URLs → clean unique array. */
    public function sanitize_url_list( $input ) {
        $lines = is_array( $input ) ? $input : preg_split( '/\r\n|\r|\n/', (string) $input );
        $out   = [];
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line ) continue;
            $url = esc_url_raw( $line );
            if ( $url ) $out[] = $url;
        }
        return array_values( array_unique( $out ) );
    }

    /* ==========================================================================
     * NEW MODULE: Post SEO + Social Media Cards
     * ========================================================================== */
    private function init_post_seo() {
        add_action( 'admin_init', [ $this, 'post_seo_register_settings' ] );
        add_action( 'tsep_render_tab_post-seo', [ $this, 'post_seo_render_settings' ] );
        add_action( 'add_meta_boxes', [ $this, 'post_seo_add_meta_box' ] );
        add_action( 'save_post', [ $this, 'post_seo_save_meta' ] );
        add_filter( 'pre_get_document_title', [ $this, 'post_seo_filter_document_title' ] );
    }

    public function post_seo_register_settings() {
        register_setting( 'tsep_options_post-seo', 'tsep_post_seo_enabled', 'absint' );
    }

    public function post_seo_render_settings() {
        $enabled = get_option( 'tsep_post_seo_enabled', 1 );
        ?>
        <h2>Post SEO & Social Media Cards</h2>
        <table class="form-table">
            <tr>
                <th>Enable Post SEO Meta Box</th>
                <td>
                    <label><input type="checkbox" name="tsep_post_seo_enabled" value="1" <?php checked( 1, $enabled ); ?>> Show the SEO + Social Cards meta box in the editor</label>
                </td>
            </tr>
        </table>
        <p><strong>Use the meta box</strong> on every post/page to set custom titles, descriptions, canonical URLs, social images, and FAQ schema.</p>
        <?php
    }

    public function post_seo_add_meta_box() {
        if ( ! get_option( 'tsep_post_seo_enabled', 1 ) ) return;
        $post_types = get_post_types( [ 'public' => true ] );
        foreach ( $post_types as $type ) {
            add_meta_box(
                'tsep_post_seo_box',
                'The SEO Playbook – Title, Description & Social Cards',
                [ $this, 'post_seo_meta_box_render' ],
                $type,
                'normal',
                'high'
            );
        }
    }

    public function post_seo_meta_box_render( $post ) {
        wp_nonce_field( 'tsep_post_seo_save', 'tsep_post_seo_nonce' );

        $seo_title       = get_post_meta( $post->ID, '_tsep_seo_title', true );
        $meta_desc       = get_post_meta( $post->ID, '_tsep_meta_description', true );
        $canonical       = get_post_meta( $post->ID, '_tsep_canonical', true );
        $og_title        = get_post_meta( $post->ID, '_tsep_og_title', true );
        $og_desc         = get_post_meta( $post->ID, '_tsep_og_description', true );
        $og_image        = get_post_meta( $post->ID, '_tsep_og_image', true );
        $twitter_title   = get_post_meta( $post->ID, '_tsep_twitter_title', true );
        $twitter_desc    = get_post_meta( $post->ID, '_tsep_twitter_description', true );
        $twitter_image   = get_post_meta( $post->ID, '_tsep_twitter_image', true );
        ?>
        <table class="form-table">
            <tr><th>SEO Title</th><td><input type="text" name="tsep_seo_title" value="<?php echo esc_attr( $seo_title ); ?>" class="widefat"></td></tr>
            <tr><th>Meta Description</th><td><textarea name="tsep_meta_description" rows="2" class="widefat"><?php echo esc_textarea( $meta_desc ); ?></textarea></td></tr>
            <tr><th>Canonical URL</th><td><input type="url" name="tsep_canonical" value="<?php echo esc_attr( $canonical ); ?>" class="widefat" placeholder="Leave blank to use this post's default permalink"></td></tr>

            <tr><th colspan="2"><strong>Open Graph (Facebook, LinkedIn, etc.)</strong></th></tr>
            <tr><th>OG Title</th><td><input type="text" name="tsep_og_title" value="<?php echo esc_attr( $og_title ); ?>" class="widefat"></td></tr>
            <tr><th>OG Description</th><td><textarea name="tsep_og_description" rows="2" class="widefat"><?php echo esc_textarea( $og_desc ); ?></textarea></td></tr>
            <tr><th>OG Image URL</th><td><input type="url" name="tsep_og_image" value="<?php echo esc_attr( $og_image ); ?>" class="widefat" placeholder="https://..."></td></tr>

            <tr><th colspan="2"><strong>Twitter Cards</strong></th></tr>
            <tr><th>Twitter Title</th><td><input type="text" name="tsep_twitter_title" value="<?php echo esc_attr( $twitter_title ); ?>" class="widefat"></td></tr>
            <tr><th>Twitter Description</th><td><textarea name="tsep_twitter_description" rows="2" class="widefat"><?php echo esc_textarea( $twitter_desc ); ?></textarea></td></tr>
            <tr><th>Twitter Image URL</th><td><input type="url" name="tsep_twitter_image" value="<?php echo esc_attr( $twitter_image ); ?>" class="widefat" placeholder="https://..."></td></tr>
        </table>
        <p><em>Leave fields blank to use automatic fallbacks (post title / excerpt / featured image).</em></p>
        <?php
    }

    public function post_seo_save_meta( $post_id ) {
        if ( ! isset( $_POST['tsep_post_seo_nonce'] ) || ! wp_verify_nonce( $_POST['tsep_post_seo_nonce'], 'tsep_post_seo_save' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $fields = [
            '_tsep_seo_title', '_tsep_meta_description',
            '_tsep_og_title', '_tsep_og_description', '_tsep_og_image',
            '_tsep_twitter_title', '_tsep_twitter_description', '_tsep_twitter_image'
        ];

        foreach ( $fields as $field ) {
            $key = str_replace( '_tsep_', 'tsep_', $field );
            if ( isset( $_POST[ $key ] ) ) {
                update_post_meta( $post_id, $field, sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) );
            }
        }

        // Canonical URL needs URL-safe sanitization.
        if ( isset( $_POST['tsep_canonical'] ) ) {
            $canonical = esc_url_raw( wp_unslash( $_POST['tsep_canonical'] ) );
            if ( $canonical ) {
                update_post_meta( $post_id, '_tsep_canonical', $canonical );
            } else {
                delete_post_meta( $post_id, '_tsep_canonical' );
            }
        }
    }

    /** Wire the custom SEO Title into the actual <title> element. */
    public function post_seo_filter_document_title( $title ) {
        if ( is_singular() ) {
            $custom = get_post_meta( get_the_ID(), '_tsep_seo_title', true );
            if ( ! empty( $custom ) ) return $custom;
        }
        return $title;
    }

    public function post_seo_output_meta_tags() {
        if ( ! is_singular() ) return;

        $post_id = get_the_ID();

        // SEO Title & Description
        $seo_title = get_post_meta( $post_id, '_tsep_seo_title', true ) ?: get_the_title();
        $meta_desc = get_post_meta( $post_id, '_tsep_meta_description', true ) ?: get_the_excerpt();

        echo '<meta name="description" content="' . esc_attr( $meta_desc ) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr( $seo_title ) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr( $meta_desc ) . '">' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr( $seo_title ) . '">' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr( $meta_desc ) . '">' . "\n";

        // Images
        $og_image      = get_post_meta( $post_id, '_tsep_og_image', true ) ?: ( has_post_thumbnail() ? get_the_post_thumbnail_url( $post_id, 'full' ) : '' );
        $twitter_image = get_post_meta( $post_id, '_tsep_twitter_image', true ) ?: $og_image;

        if ( $og_image ) {
            echo '<meta property="og:image" content="' . esc_url( $og_image ) . '">' . "\n";
        }
        if ( $twitter_image ) {
            echo '<meta name="twitter:image" content="' . esc_url( $twitter_image ) . '">' . "\n";
            echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
        }
    }

    /* ==========================================================================
     * NEW MODULE: FAQ Schema (per-post FAQPage JSON-LD)
     * ========================================================================== */
    private function init_faq_schema() {
        add_action( 'add_meta_boxes', [ $this, 'faq_add_meta_box' ] );
        add_action( 'save_post', [ $this, 'faq_save_meta' ] );
        add_action( 'wp_head', [ $this, 'faq_output_schema' ], 7 );
    }

    public function faq_add_meta_box() {
        if ( ! get_option( 'tsep_post_seo_enabled', 1 ) ) return;
        foreach ( get_post_types( [ 'public' => true ] ) as $type ) {
            add_meta_box(
                'tsep_faq_box',
                'The SEO Playbook – FAQ Schema',
                [ $this, 'faq_meta_box_render' ],
                $type,
                'normal',
                'default'
            );
        }
    }

    public function faq_meta_box_render( $post ) {
        wp_nonce_field( 'tsep_faq_save', 'tsep_faq_nonce' );
        $faqs = get_post_meta( $post->ID, '_tsep_faq', true );
        if ( ! is_array( $faqs ) || empty( $faqs ) ) {
            $faqs = [ [ 'q' => '', 'a' => '' ] ];
        }
        ?>
        <div id="tsep-faq-rows">
            <?php foreach ( $faqs as $faq ) : ?>
                <div class="tsep-faq-row" style="margin-bottom:12px;border-left:3px solid #ccd0d4;padding-left:10px;">
                    <input type="text" name="tsep_faq_q[]" value="<?php echo esc_attr( $faq['q'] ); ?>" class="widefat" placeholder="Question" style="margin-bottom:4px;">
                    <textarea name="tsep_faq_a[]" rows="2" class="widefat" placeholder="Answer"><?php echo esc_textarea( $faq['a'] ); ?></textarea>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="button" class="button" id="tsep-faq-add">+ Add FAQ</button>
        <p class="description">Adds <code>FAQPage</code> schema (JSON-LD) so AI engines and Google can surface these questions and answers. Leave blank to skip.</p>
        <script>
        (function(){
            var add = document.getElementById('tsep-faq-add');
            if ( ! add ) return;
            add.addEventListener('click', function(){
                var rows  = document.getElementById('tsep-faq-rows');
                var tpl   = rows.querySelector('.tsep-faq-row');
                var clone = tpl.cloneNode(true);
                clone.querySelectorAll('input, textarea').forEach(function(el){ el.value = ''; });
                rows.appendChild(clone);
            });
        })();
        </script>
        <?php
    }

    public function faq_save_meta( $post_id ) {
        if ( ! isset( $_POST['tsep_faq_nonce'] ) || ! wp_verify_nonce( $_POST['tsep_faq_nonce'], 'tsep_faq_save' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $questions = isset( $_POST['tsep_faq_q'] ) ? (array) wp_unslash( $_POST['tsep_faq_q'] ) : [];
        $answers   = isset( $_POST['tsep_faq_a'] ) ? (array) wp_unslash( $_POST['tsep_faq_a'] ) : [];

        $faqs = [];
        foreach ( $questions as $i => $q ) {
            $q = sanitize_text_field( $q );
            $a = isset( $answers[ $i ] ) ? sanitize_textarea_field( $answers[ $i ] ) : '';
            if ( '' === $q && '' === $a ) continue;
            $faqs[] = [ 'q' => $q, 'a' => $a ];
        }

        if ( $faqs ) {
            update_post_meta( $post_id, '_tsep_faq', $faqs );
        } else {
            delete_post_meta( $post_id, '_tsep_faq' );
        }
    }

    public function faq_output_schema() {
        if ( ! is_singular() ) return;
        $faqs = get_post_meta( get_the_ID(), '_tsep_faq', true );
        if ( ! is_array( $faqs ) || empty( $faqs ) ) return;

        $main = [];
        foreach ( $faqs as $faq ) {
            if ( empty( $faq['q'] ) ) continue;
            $main[] = [
                '@type'          => 'Question',
                'name'           => $faq['q'],
                'acceptedAnswer' => [
                    '@type' => 'Answer',
                    'text'  => $faq['a'],
                ],
            ];
        }
        if ( empty( $main ) ) return;

        $schema = [
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $main,
        ];
        echo '<script type="application/ld+json">' . wp_json_encode( $schema ) . '</script>' . "\n";
    }

    /* ==========================================================================
     * NEW MODULE: Canonical URLs
     * ========================================================================== */
    private function init_canonical() {
        remove_action( 'wp_head', 'rel_canonical' ); // Replace core's singular-only canonical.
        add_action( 'wp_head', [ $this, 'output_canonical' ], 1 );
    }

    public function output_canonical() {
        if ( is_feed() ) return;

        $url = '';
        if ( is_front_page() ) {
            $url = home_url( '/' );
        } elseif ( is_singular() ) {
            $pid    = get_the_ID();
            $custom = get_post_meta( $pid, '_tsep_canonical', true );
            $url    = $custom ?: get_permalink( $pid );
        } elseif ( is_category() || is_tag() || is_tax() ) {
            $term = get_term_link( get_queried_object() );
            if ( ! is_wp_error( $term ) ) $url = $term;
        } elseif ( is_post_type_archive() ) {
            $url = get_post_type_archive_link( get_post_type() );
        } elseif ( is_author() ) {
            $url = get_author_posts_url( get_queried_object_id() );
        } elseif ( is_home() ) {
            $blog_page = (int) get_option( 'page_for_posts' );
            if ( $blog_page ) $url = get_permalink( $blog_page );
        }

        if ( $url ) {
            echo '<link rel="canonical" href="' . esc_url( $url ) . '">' . "\n";
        }
    }

    /* ==========================================================================
     * 1. Schema Markup (Organization + sameAs + founder link)
     * ========================================================================== */
    private function init_schema() {
        add_action( 'admin_init', [ $this, 'schema_register_settings' ] );
        add_action( 'tsep_render_tab_schema', [ $this, 'schema_render_settings' ] );
        add_action( 'wp_head', [ $this, 'schema_output' ], 5 );
    }
    public function schema_register_settings() {
        register_setting( 'tsep_options_schema', 'tsep_schema_enabled', 'absint' );
        register_setting( 'tsep_options_schema', 'tsep_schema_organization_name', 'sanitize_text_field' );
        register_setting( 'tsep_options_schema', 'tsep_schema_same_as', [ $this, 'sanitize_url_list' ] );
        register_setting( 'tsep_options_schema', 'tsep_schema_enable_article', 'absint' );
        register_setting( 'tsep_options_schema', 'tsep_schema_enable_newsarticle', 'absint' );
    }
    public function schema_render_settings() {
        $same_as = (array) get_option( 'tsep_schema_same_as', [] );
        ?>
        <h2>Schema Markup Generator</h2>
        <table class="form-table">
            <tr><th>Enable Schema</th><td><input type="checkbox" name="tsep_schema_enabled" value="1" <?php checked( 1, get_option( 'tsep_schema_enabled', 1 ) ); ?>></td></tr>
            <tr><th>Organization Name</th><td><input type="text" name="tsep_schema_organization_name" value="<?php echo esc_attr( get_option( 'tsep_schema_organization_name', get_bloginfo( 'name' ) ) ); ?>" class="regular-text"></td></tr>
            <tr>
                <th>Organization Profiles (sameAs)</th>
                <td>
                    <textarea name="tsep_schema_same_as" rows="4" class="large-text code" placeholder="https://www.linkedin.com/company/...&#10;https://x.com/..."><?php echo esc_textarea( implode( "\n", $same_as ) ); ?></textarea>
                    <p class="description">One URL per line. If left blank, the Person profile links above are used so AI engines connect the two entities.</p>
                </td>
            </tr>
            <tr><th>Post Schema Types</th><td>
                <label><input type="checkbox" name="tsep_schema_enable_article" value="1" <?php checked( 1, get_option( 'tsep_schema_enable_article', 1 ) ); ?>> Article</label><br>
                <label><input type="checkbox" name="tsep_schema_enable_newsarticle" value="1" <?php checked( 1, get_option( 'tsep_schema_enable_newsarticle', 1 ) ); ?>> NewsArticle</label>
            </td></tr>
        </table>
        <hr>
        <?php
    }
    public function schema_output() {
        if ( ! get_option( 'tsep_schema_enabled', 1 ) ) return;

        // Securely build JSON-LD using arrays
        $org_schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'Organization',
            '@id'      => home_url( '/#organization' ),
            'name'     => get_option( 'tsep_schema_organization_name', get_bloginfo( 'name' ) ),
            'url'      => home_url(),
        ];

        // sameAs: organization links, falling back to the Person's links.
        $org_same_as = (array) get_option( 'tsep_schema_same_as', [] );
        if ( empty( $org_same_as ) && get_option( 'tsep_person_enabled', 1 ) ) {
            $org_same_as = (array) get_option( 'tsep_person_same_as', [] );
        }
        if ( ! empty( $org_same_as ) ) {
            $org_schema['sameAs'] = array_values( $org_same_as );
        }

        // Link the Person entity in as founder so the two entities reinforce each other.
        if ( get_option( 'tsep_person_enabled', 1 ) && get_option( 'tsep_person_name', '' ) ) {
            $org_schema['founder'] = [
                '@type' => 'Person',
                '@id'   => home_url( '/#person' ),
                'name'  => get_option( 'tsep_person_name', '' ),
            ];
        }

        echo '<script type="application/ld+json">' . wp_json_encode( $org_schema ) . '</script>' . "\n";

        if ( is_single() ) {
            if ( get_option( 'tsep_schema_enable_newsarticle', 1 ) ) {
                $news_schema = [
                    '@context'      => 'https://schema.org',
                    '@type'         => 'NewsArticle',
                    'headline'      => get_the_title(),
                    'datePublished' => get_the_date( 'c' ),
                ];
                echo '<script type="application/ld+json">' . wp_json_encode( $news_schema ) . '</script>' . "\n";
            }
            if ( get_option( 'tsep_schema_enable_article', 1 ) ) {
                $article_schema = [
                    '@context' => 'https://schema.org',
                    '@type'    => 'Article',
                    'headline' => get_the_title(),
                ];
                echo '<script type="application/ld+json">' . wp_json_encode( $article_schema ) . '</script>' . "\n";
            }
        }
    }

    /* ==========================================================================
     * 2. Local SEO
     * ========================================================================== */
    private function init_local_seo() {
        add_action( 'admin_init', [ $this, 'local_register_settings' ] );
        add_action( 'tsep_render_tab_schema', [ $this, 'local_render_settings' ] );
        add_shortcode( 'tsep_local_business', [ $this, 'local_shortcode' ] );
    }
    public function local_register_settings() {
        register_setting( 'tsep_options_schema', 'tsep_local_enabled', 'absint' );
        register_setting( 'tsep_options_schema', 'tsep_local_name', 'sanitize_text_field' );
    }
    public function local_render_settings() {
        ?>
        <h2>Local SEO</h2>
        <table class="form-table">
            <tr><th>Enable Local SEO</th><td><input type="checkbox" name="tsep_local_enabled" value="1" <?php checked( 1, get_option( 'tsep_local_enabled', 1 ) ); ?>></td></tr>
            <tr><th>Local Business Name</th><td><input type="text" name="tsep_local_name" value="<?php echo esc_attr( get_option( 'tsep_local_name' ) ); ?>" class="regular-text"></td></tr>
        </table>
        <?php
    }
    public function local_shortcode() {
        return '<div class="tsep-local-business"><h3>' . esc_html( get_option( 'tsep_local_name', get_bloginfo( 'name' ) ) ) . '</h3></div>';
    }

    /* ==========================================================================
     * 3. Smart XML Sitemaps
     * ========================================================================== */
    private function init_sitemaps() {
        add_action( 'init', [ $this, 'sitemap_add_rewrite_rule' ] );
        add_filter( 'query_vars', [ $this, 'sitemap_register_query_var' ] );
        add_action( 'template_redirect', [ $this, 'sitemap_render' ] );
        add_action( 'admin_init', [ $this, 'sitemap_register_settings' ] );
        add_action( 'tsep_render_tab_sitemaps', [ $this, 'sitemap_render_settings' ] );
    }
    public function sitemap_add_rewrite_rule() { add_rewrite_rule( '^sitemap\.xml$', 'index.php?tsep_sitemap=1', 'top' ); }
    public function sitemap_register_query_var( $vars ) { $vars[] = 'tsep_sitemap'; return $vars; }
    public function sitemap_register_settings() { register_setting( 'tsep_options_sitemaps', 'tsep_sitemap_post_types', [ $this, 'sanitize_post_types' ] ); }
    public function sanitize_post_types( $input ) { return array_intersect( (array) $input, get_post_types( [ 'public' => true ], 'names' ) ); }
    public function sitemap_render_settings() {
        $saved = get_option( 'tsep_sitemap_post_types', [ 'post', 'page' ] );
        ?>
        <h2>Smart XML Sitemaps</h2>
        <table class="form-table">
            <tr>
                <th>Include Post Types</th>
                <td>
                    <?php foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $type ) {
                        $checked = in_array( $type->name, $saved, true ) ? 'checked' : '';
                        echo '<label><input type="checkbox" name="tsep_sitemap_post_types[]" value="' . esc_attr( $type->name ) . '" ' . $checked . '> ' . esc_html( $type->labels->name ) . '</label><br>';
                    } ?>
                </td>
            </tr>
        </table>
        <hr>
        <?php
    }
    public function sitemap_render() {
        if ( ! get_query_var( 'tsep_sitemap' ) ) return;
        header( 'Content-Type: application/xml; charset=utf-8' );
        $post_types = get_option( 'tsep_sitemap_post_types', [ 'post', 'page' ] ) ?: [ 'post', 'page' ];

        echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        foreach ( $post_types as $type ) {
            // Limited to 1000 to prevent Fatal OOM Errors
            foreach ( get_posts( [ 'post_type' => $type, 'post_status' => 'publish', 'posts_per_page' => 1000 ] ) as $post ) {
                echo '<url><loc>' . esc_url( get_permalink( $post ) ) . '</loc><lastmod>' . get_post_modified_time( 'c', true, $post ) . '</lastmod></url>';
            }
        }
        echo '</urlset>';
        exit;
    }

    /* ==========================================================================
     * 4. Google News Sitemap
     * ========================================================================== */
    private function init_google_news_sitemap() {
        add_action( 'init', [ $this, 'news_sitemap_add_rewrite_rule' ] );
        add_filter( 'query_vars', [ $this, 'news_sitemap_register_query_var' ] );
        add_action( 'template_redirect', [ $this, 'news_sitemap_render' ] );
        add_action( 'admin_init', [ $this, 'news_sitemap_register_settings' ] );
        add_action( 'tsep_render_tab_sitemaps', [ $this, 'news_sitemap_render_settings' ] );
    }
    public function news_sitemap_add_rewrite_rule() { add_rewrite_rule( '^news-sitemap\.xml$', 'index.php?tsep_news_sitemap=1', 'top' ); }
    public function news_sitemap_register_query_var( $vars ) { $vars[] = 'tsep_news_sitemap'; return $vars; }
    public function news_sitemap_register_settings() {
        register_setting( 'tsep_options_sitemaps', 'tsep_news_sitemap_enabled', 'absint' );
        register_setting( 'tsep_options_sitemaps', 'tsep_news_publication_name', 'sanitize_text_field' );
    }
    public function news_sitemap_render_settings() {
        ?>
        <h2>Google News Sitemap</h2>
        <table class="form-table">
            <tr><th>Enable News Sitemap</th><td><input type="checkbox" name="tsep_news_sitemap_enabled" value="1" <?php checked( 1, get_option( 'tsep_news_sitemap_enabled', 1 ) ); ?>></td></tr>
            <tr><th>Publication Name</th><td><input type="text" name="tsep_news_publication_name" value="<?php echo esc_attr( get_option( 'tsep_news_publication_name', get_bloginfo( 'name' ) ) ); ?>" class="regular-text"></td></tr>
        </table>
        <?php
    }
    public function news_sitemap_render() {
        if ( ! get_query_var( 'tsep_news_sitemap' ) || ! get_option( 'tsep_news_sitemap_enabled', 1 ) ) return;
        header( 'Content-Type: application/xml; charset=utf-8' );
        echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">';

        // Ensure strictly only posts published in the last 48 hours are included
        $news_args = [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'date_query'     => [
                [ 'column' => 'post_date_gmt', 'after' => '48 hours ago' ]
            ]
        ];
        foreach ( get_posts( $news_args ) as $post ) {
            echo '<url><loc>' . esc_url( get_permalink( $post ) ) . '</loc><news:news><news:publication><news:name>' . esc_html( get_option( 'tsep_news_publication_name', get_bloginfo( 'name' ) ) ) . '</news:name><news:language>en</news:language></news:publication><news:title>' . esc_html( get_the_title( $post ) ) . '</news:title></news:news></url>';
        }
        echo '</urlset>';
        exit;
    }

    /* ==========================================================================
     * 5. On-Page Analysis
     * ========================================================================== */
    private function init_onpage_analysis() {
        add_action( 'admin_init', [ $this, 'onpage_register_settings' ] );
        add_action( 'tsep_render_tab_onpage', [ $this, 'onpage_render_settings' ] );
        add_action( 'add_meta_boxes', [ $this, 'onpage_add_meta_box' ] );
        add_action( 'save_post', [ $this, 'onpage_save_meta' ] );
    }
    public function onpage_register_settings() { register_setting( 'tsep_options_onpage', 'tsep_onpage_enabled', 'absint' ); }
    public function onpage_render_settings() {
        ?>
        <h2>On-Page Analysis Checklist</h2>
        <table class="form-table">
            <tr><th>Enable Checklist</th><td><input type="checkbox" name="tsep_onpage_enabled" value="1" <?php checked( 1, get_option( 'tsep_onpage_enabled', 1 ) ); ?>></td></tr>
        </table>
        <hr>
        <?php
    }
    public function onpage_add_meta_box() {
        if ( get_option( 'tsep_onpage_enabled', 1 ) ) {
            add_meta_box( 'tsep_onpage_checklist', 'On-Page SEO Checklist', [ $this, 'onpage_meta_box_render' ], null, 'side', 'high' );
        }
    }
    public function onpage_meta_box_render( $post ) {
        wp_nonce_field( 'tsep_save_onpage', 'tsep_onpage_nonce' );
        $keyword = get_post_meta( $post->ID, '_tsep_focus_keyword', true );
        ?>
        <label>Focus Keyword</label>
        <input type="text" name="tsep_focus_keyword" id="tsep_focus_keyword" value="<?php echo esc_attr( $keyword ); ?>" class="widefat">
        <button type="button" id="tsep-run-analysis" class="button button-primary" style="margin-top:10px;">Run Checklist</button>
        <div id="tsep-checklist-results" style="margin-top:15px;"></div>
        <?php
    }
    public function onpage_save_meta( $post_id ) {
        if ( ! isset( $_POST['tsep_onpage_nonce'] ) || ! wp_verify_nonce( $_POST['tsep_onpage_nonce'], 'tsep_save_onpage' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        if ( isset( $_POST['tsep_focus_keyword'] ) ) {
            update_post_meta( $post_id, '_tsep_focus_keyword', sanitize_text_field( wp_unslash( $_POST['tsep_focus_keyword'] ) ) );
        }
    }

    /* ==========================================================================
     * 6. Breadcrumbs
     * ========================================================================== */
    private function init_breadcrumbs() {
        add_action( 'admin_init', [ $this, 'breadcrumbs_register_settings' ] );
        add_action( 'tsep_render_tab_onpage', [ $this, 'breadcrumbs_render_settings' ] );
        add_shortcode( 'tsep_breadcrumbs', [ $this, 'breadcrumbs_shortcode' ] );
        add_action( 'wp', [ $this, 'breadcrumbs_maybe_auto_display' ] );
    }
    public function breadcrumbs_register_settings() {
        register_setting( 'tsep_options_onpage', 'tsep_breadcrumbs_enabled', 'absint' );
        register_setting( 'tsep_options_onpage', 'tsep_breadcrumbs_separator', 'sanitize_text_field' );
        register_setting( 'tsep_options_onpage', 'tsep_breadcrumbs_auto_display', 'absint' );
    }
    public function breadcrumbs_render_settings() {
        ?>
        <h2>Breadcrumbs</h2>
        <table class="form-table">
            <tr><th>Enable Breadcrumbs</th><td><input type="checkbox" name="tsep_breadcrumbs_enabled" value="1" <?php checked( 1, get_option( 'tsep_breadcrumbs_enabled', 1 ) ); ?>></td></tr>
            <tr><th>Auto-display on Single Posts</th><td><input type="checkbox" name="tsep_breadcrumbs_auto_display" value="1" <?php checked( 1, get_option( 'tsep_breadcrumbs_auto_display', 0 ) ); ?>></td></tr>
            <tr><th>Separator</th><td><input type="text" name="tsep_breadcrumbs_separator" value="<?php echo esc_attr( get_option( 'tsep_breadcrumbs_separator', '›' ) ); ?>" class="regular-text" style="width:80px;"></td></tr>
        </table>
        <?php
    }
    public function breadcrumbs_shortcode() { return $this->get_breadcrumbs_html(); }
    public function breadcrumbs_maybe_auto_display() {
        if ( get_option( 'tsep_breadcrumbs_auto_display', 0 ) ) {
            add_filter( 'the_content', [ $this, 'breadcrumbs_auto_insert' ], 5 );
        }
    }
    public function breadcrumbs_auto_insert( $content ) {
        if ( is_singular() ) return $this->get_breadcrumbs_html() . $content;
        return $content;
    }
    private function get_breadcrumbs_html() {
        if ( ! get_option( 'tsep_breadcrumbs_enabled', 1 ) ) return '';
        $separator = get_option( 'tsep_breadcrumbs_separator', '›' );
        $items = [
            [ 'name' => 'Home', 'url' => home_url( '/' ), 'link' => true ],
            [ 'name' => get_the_title(), 'url' => '', 'link' => false ]
        ];

        $html = '<nav class="tsep-breadcrumbs" style="margin-bottom:15px;" itemscope itemtype="https://schema.org/BreadcrumbList">';

        $position = 1;
        foreach ( $items as $i => $item ) {
            $html .= '<span itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';
            if ( $item['link'] ) $html .= '<a href="' . esc_url( $item['url'] ) . '" itemprop="item">';
            $html .= '<span itemprop="name">' . esc_html( $item['name'] ) . '</span>';
            if ( $item['link'] ) $html .= '</a>';

            $html .= '<meta itemprop="position" content="' . $position . '" />';
            $html .= '</span> ';

            if ( $item['link'] ) $html .= esc_html( $separator ) . ' ';
            $position++;
        }
        $html .= '</nav>';
        return $html;
    }

    /* ==========================================================================
     * 7. IndexNow
     * ========================================================================== */
    private function init_indexnow() {
        add_action( 'admin_init', [ $this, 'indexnow_register_settings' ] );
        add_action( 'tsep_render_tab_advanced', [ $this, 'indexnow_render_settings' ] );
        add_action( 'transition_post_status', [ $this, 'indexnow_on_status_change' ], 10, 3 );
        add_action( 'tsep_indexnow_ping_event', [ $this, 'indexnow_execute_ping' ] );
    }
    public function indexnow_register_settings() {
        register_setting( 'tsep_options_advanced', 'tsep_indexnow_key', 'sanitize_text_field' );
        register_setting( 'tsep_options_advanced', 'tsep_indexnow_enabled', 'absint' );
    }
    public function indexnow_render_settings() {
        ?>
        <h2>IndexNow API</h2>
        <table class="form-table">
            <tr><th>Enable IndexNow</th><td><input type="checkbox" name="tsep_indexnow_enabled" value="1" <?php checked( 1, get_option( 'tsep_indexnow_enabled', 1 ) ); ?>></td></tr>
            <tr><th>API Key</th><td><input type="text" name="tsep_indexnow_key" value="<?php echo esc_attr( get_option( 'tsep_indexnow_key' ) ); ?>" class="regular-text" /></td></tr>
        </table>
        <hr>
        <?php
    }
    public function indexnow_on_status_change( $new, $old, $post ) {
        if ( get_option( 'tsep_indexnow_enabled', 1 ) && $new === 'publish' && ! wp_is_post_revision( $post ) ) {
            wp_schedule_single_event( time(), 'tsep_indexnow_ping_event', [ get_permalink( $post ) ] );
        }
    }
    public function indexnow_execute_ping( $url ) {
        $key = get_option( 'tsep_indexnow_key' );
        if ( empty( $key ) ) return;
        wp_remote_post( 'https://api.indexnow.org/indexnow', [
            'timeout' => 10,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( [ 'host' => parse_url( home_url(), PHP_URL_HOST ), 'key' => $key, 'urlList' => [ $url ] ] )
        ] );
    }

    /* ==========================================================================
     * 8. LLMs.txt Generator (enhanced: structured listings + llms-full.txt)
     * ========================================================================== */
    private function init_llms_txt() {
        add_action( 'init', [ $this, 'llms_add_rewrite_rule' ] );
        add_filter( 'query_vars', [ $this, 'llms_register_query_var' ] );
        add_action( 'template_redirect', [ $this, 'llms_render' ] );
        add_action( 'admin_init', [ $this, 'llms_register_settings' ] );
        add_action( 'tsep_render_tab_advanced', [ $this, 'llms_render_settings' ] );
    }
    public function llms_add_rewrite_rule() {
        add_rewrite_rule( '^llms\.txt$', 'index.php?tsep_llms_txt=1', 'top' );
        add_rewrite_rule( '^llms-full\.txt$', 'index.php?tsep_llms_full_txt=1', 'top' );
    }
    public function llms_register_query_var( $vars ) {
        $vars[] = 'tsep_llms_txt';
        $vars[] = 'tsep_llms_full_txt';
        return $vars;
    }
    public function llms_register_settings() {
        register_setting( 'tsep_options_advanced', 'tsep_llms_txt_content', 'wp_kses_post' );
        register_setting( 'tsep_options_advanced', 'tsep_llms_auto_generate', 'absint' );
    }
    public function llms_render_settings() {
        ?>
        <h2>LLMs.txt Generator</h2>
        <table class="form-table">
            <tr>
                <th>Auto-generate listings</th>
                <td>
                    <label><input type="checkbox" name="tsep_llms_auto_generate" value="1" <?php checked( 1, get_option( 'tsep_llms_auto_generate', 1 ) ); ?>> Automatically list pages &amp; posts (title, link, summary) for AI crawlers</label>
                    <p class="description">Serves a structured file at <code><?php echo esc_url( home_url( '/llms.txt' ) ); ?></code> and a fuller version (with content) at <code><?php echo esc_url( home_url( '/llms-full.txt' ) ); ?></code>.</p>
                </td>
            </tr>
            <tr>
                <th>Custom intro / content</th>
                <td>
                    <textarea name="tsep_llms_txt_content" rows="6" class="large-text code"><?php echo esc_textarea( get_option( 'tsep_llms_txt_content', '' ) ); ?></textarea>
                    <p class="description">Optional. Placed near the top, just under the site title.</p>
                </td>
            </tr>
        </table>
        <hr>
        <?php
    }
    public function llms_render() {
        if ( get_query_var( 'tsep_llms_txt' ) ) {
            header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );
            echo $this->llms_build_content( false );
            exit;
        }
        if ( get_query_var( 'tsep_llms_full_txt' ) ) {
            header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );
            echo $this->llms_build_content( true );
            exit;
        }
    }

    private function llms_build_content( $full = false ) {
        $name   = get_bloginfo( 'name' );
        $desc   = get_bloginfo( 'description' );
        $custom = trim( (string) get_option( 'tsep_llms_txt_content', '' ) );
        $auto   = get_option( 'tsep_llms_auto_generate', 1 );

        $out = '# ' . $name . "\n";
        if ( $desc ) $out .= '> ' . $desc . "\n";
        $out .= "\n";

        if ( '' !== $custom ) {
            $out .= wp_strip_all_tags( $custom ) . "\n\n";
        }

        // Person / author block.
        if ( get_option( 'tsep_person_enabled', 1 ) && get_option( 'tsep_person_name', '' ) ) {
            $out .= "## About\n";
            $out .= '- Name: ' . get_option( 'tsep_person_name', '' ) . "\n";
            if ( $job = get_option( 'tsep_person_job_title', '' ) ) $out .= '- Title: ' . $job . "\n";
            if ( $bio = get_option( 'tsep_person_bio', '' ) ) $out .= '- Bio: ' . trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $bio ) ) ) . "\n";
            foreach ( (array) get_option( 'tsep_person_same_as', [] ) as $link ) {
                $out .= '- Profile: ' . $link . "\n";
            }
            $out .= "\n";
        }

        if ( ! $auto ) {
            return $out;
        }

        // Pages
        $pages = get_posts( [
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => $full ? 100 : 200,
            'orderby'        => 'menu_order title',
            'order'          => 'ASC',
        ] );
        if ( $pages ) {
            $out .= "## Pages\n";
            foreach ( $pages as $p ) {
                $out .= $this->llms_entry( $p, $full );
            }
            $out .= "\n";
        }

        // Posts
        $posts = get_posts( [
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => $full ? 200 : 500,
        ] );
        if ( $posts ) {
            $out .= "## Posts\n";
            foreach ( $posts as $p ) {
                $out .= $this->llms_entry( $p, $full );
            }
            $out .= "\n";
        }

        return $out;
    }

    private function llms_entry( $p, $full ) {
        $title = get_the_title( $p );
        $url   = get_permalink( $p );
        $line  = '- [' . $title . '](' . $url . ')';

        $excerpt = has_excerpt( $p ) ? get_the_excerpt( $p ) : wp_trim_words( wp_strip_all_tags( strip_shortcodes( $p->post_content ) ), $full ? 120 : 30, '' );
        $excerpt = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $excerpt ) ) );
        if ( '' !== $excerpt ) {
            $line .= ': ' . $excerpt;
        }
        $line .= "\n";

        if ( $full ) {
            $content = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( strip_shortcodes( $p->post_content ) ) ) );
            if ( '' !== $content ) {
                $line .= '  ' . $content . "\n";
            }
        }
        return $line;
    }

    /* ==========================================================================
     * 9. Robots.txt Editor
     * ========================================================================== */
    private function init_robots_txt() {
        add_action( 'init', [ $this, 'robots_add_rewrite_rule' ] );
        add_filter( 'query_vars', [ $this, 'robots_register_query_var' ] );
        add_action( 'template_redirect', [ $this, 'robots_render' ] );
        add_action( 'admin_init', [ $this, 'robots_register_settings' ] );
        add_action( 'tsep_render_tab_advanced', [ $this, 'robots_render_settings' ] );
        remove_action( 'do_robots', 'do_robots' );
        add_action( 'do_robots', [ $this, 'robots_override_default' ] );
    }
    public function robots_add_rewrite_rule() { add_rewrite_rule( '^robots\.txt$', 'index.php?tsep_robots_txt=1', 'top' ); }
    public function robots_register_query_var( $vars ) { $vars[] = 'tsep_robots_txt'; return $vars; }
    public function robots_register_settings() { register_setting( 'tsep_options_advanced', 'tsep_robots_content', 'wp_strip_all_tags' ); }
    public function robots_render_settings() {
        ?>
        <h2>Robots.txt Editor</h2>
        <table class="form-table">
            <tr>
                <th>Custom Robots.txt</th>
                <td><textarea name="tsep_robots_content" rows="6" class="large-text code"><?php echo esc_textarea( get_option( 'tsep_robots_content', '' ) ); ?></textarea></td>
            </tr>
        </table>
        <hr>
        <?php
    }
    public function robots_render() {
        if ( get_query_var( 'tsep_robots_txt' ) ) {
            header( 'Content-Type: text/plain; charset=' . get_option( 'blog_charset' ) );
            $custom = get_option( 'tsep_robots_content', '' );
            echo ! empty( trim( $custom ) ) ? wp_strip_all_tags( $custom ) : "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: " . home_url('/sitemap.xml');
            exit;
        }
    }
    public function robots_override_default() { $this->robots_render(); }

    /* ==========================================================================
     * 10. Webmaster Tools
     * ========================================================================== */
    private function init_webmaster_tools() {
        add_action( 'admin_init', [ $this, 'webmaster_register_settings' ] );
        add_action( 'tsep_render_tab_advanced', [ $this, 'webmaster_render_settings' ] );
        add_action( 'wp_head', [ $this, 'webmaster_output_meta_tags' ] );
    }
    public function webmaster_register_settings() {
        register_setting( 'tsep_options_advanced', 'tsep_google_verify', 'sanitize_text_field' );
        register_setting( 'tsep_options_advanced', 'tsep_bing_verify', 'sanitize_text_field' );
    }
    public function webmaster_render_settings() {
        ?>
        <h2>Webmaster Tools Verification</h2>
        <table class="form-table">
            <tr><th>Google Search Console</th><td><input type="text" name="tsep_google_verify" value="<?php echo esc_attr( get_option( 'tsep_google_verify' ) ); ?>" class="regular-text" /></td></tr>
            <tr><th>Bing Webmaster</th><td><input type="text" name="tsep_bing_verify" value="<?php echo esc_attr( get_option( 'tsep_bing_verify' ) ); ?>" class="regular-text" /></td></tr>
        </table>
        <?php
    }
    public function webmaster_output_meta_tags() {
        if ( $google = get_option( 'tsep_google_verify' ) ) echo '<meta name="google-site-verification" content="' . esc_attr( $google ) . '">' . "\n";
        if ( $bing   = get_option( 'tsep_bing_verify' ) )   echo '<meta name="msvalidate.01" content="' . esc_attr( $bing ) . '">' . "\n";
    }

    /* ==========================================================================
     * AUTO-UPDATER: pulls updates from GitHub via update-info.json
     * ========================================================================== */
    private function init_updater() {
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'updater_inject' ] );
        add_filter( 'plugins_api', [ $this, 'updater_plugin_info' ], 20, 3 );
        add_filter( 'upgrader_source_selection', [ $this, 'updater_fix_source_dir' ], 10, 4 );
    }

    private function updater_manifest_url() {
        return 'https://raw.githubusercontent.com/whattheheehaw/wordpress-seo-plugin/main/update-info.json';
    }

    private function updater_get_manifest() {
        $cached = get_transient( 'tsep_update_manifest' );
        if ( $cached ) return $cached;

        $response = wp_remote_get( $this->updater_manifest_url(), [ 'timeout' => 10 ] );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }
        $data = json_decode( wp_remote_retrieve_body( $response ) );
        if ( empty( $data->version ) ) return null;

        set_transient( 'tsep_update_manifest', $data, 12 * HOUR_IN_SECONDS );
        return $data;
    }

    public function updater_inject( $transient ) {
        if ( empty( $transient->checked ) ) return $transient;

        $manifest = $this->updater_get_manifest();
        if ( ! $manifest ) return $transient;

        $plugin_file     = plugin_basename( __FILE__ );
        $current_version = $transient->checked[ $plugin_file ] ?? TSEP_VERSION;

        if ( version_compare( $manifest->version, $current_version, '>' ) ) {
            $transient->response[ $plugin_file ] = (object) [
                'slug'        => 'the-seo-playbook',
                'plugin'      => $plugin_file,
                'new_version' => $manifest->version,
                'url'         => 'https://github.com/whattheheehaw/wordpress-seo-plugin',
                'package'     => $manifest->download_url,
            ];
        }
        return $transient;
    }

    public function updater_plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) return $result;
        if ( empty( $args->slug ) || 'the-seo-playbook' !== $args->slug ) return $result;

        $manifest = $this->updater_get_manifest();
        if ( ! $manifest ) return $result;

        return (object) [
            'name'          => 'The SEO Playbook',
            'slug'          => 'the-seo-playbook',
            'version'       => $manifest->version,
            'author'        => 'Amelia Hollis',
            'homepage'      => 'https://github.com/whattheheehaw/wordpress-seo-plugin',
            'download_link' => $manifest->download_url,
            'requires'      => '6.4',
            'requires_php'  => '8.0',
            'last_updated'  => $manifest->last_updated ?? '',
            'sections'      => [
                'description' => 'A modular SEO suite with Person entity, FAQ schema, canonical URLs, and AI-optimised llms.txt.',
                'changelog'   => $manifest->changelog ?? '',
            ],
        ];
    }

    /**
     * GitHub archive zips unpack to a folder named after the repo (e.g. wordpress-seo-plugin-main).
     * WordPress requires the folder to match the plugin slug. This filter renames it before install.
     */
    public function updater_fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = [] ) {
        if ( empty( $hook_extra['plugin'] ) || plugin_basename( __FILE__ ) !== $hook_extra['plugin'] ) {
            return $source;
        }

        $expected = trailingslashit( dirname( untrailingslashit( $source ) ) ) . 'the-seo-playbook/';
        if ( trailingslashit( $source ) === $expected ) return $source;

        global $wp_filesystem;
        if ( $wp_filesystem && $wp_filesystem->move( $source, $expected ) ) {
            return $expected;
        }
        return $source;
    }
}

// Initialize
The_SEO_Playbook::get_instance();
