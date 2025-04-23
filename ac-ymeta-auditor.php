<?php
/**
 * Plugin Name: Yoast Meta Auditor
 * Description: Audit Yoast meta fields with unified filters, sorting, sleek pagination, CSV export and WP All Import helper.
 * Version: 0.6
 * Author: Adam Chiaravalle
 *
 * DISCLAIMER: No warranty. Always back-up before installing plugins or running imports.
 */

/*--------------------------------------------------  setup */
add_action('admin_init', fn() => register_setting(
    'yoast_meta_auditor_settings', 'yoast_meta_auditor_post_types'
));
add_action('admin_menu', fn() => add_menu_page(
    'Yoast Meta Auditor', 'Yoast Meta Auditor', 'manage_options',
    'yoast-meta-auditor', 'yma_render_page', 'dashicons-search', 90
));

/*--------------------------------------------------  one-click WP All Import installer */
add_action('admin_post_yma_install_wpai', 'yma_install_wpai');
function yma_install_wpai() {
    if ( ! current_user_can('install_plugins')
         || ! check_admin_referer('yma_install_wpai') ) {
        wp_die('Permission denied.');
    }

    require_once ABSPATH.'wp-admin/includes/plugin-install.php';
    require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';

    $api = plugins_api('plugin_information', [
        'slug'   => 'wp-all-import',
        'fields' => [ 'sections' => false ]
    ]);

    (new Plugin_Upgrader(
        new Plugin_Installer_Skin([ 'skip_header_footer' => true ])
    ))->install($api->download_link);

    wp_safe_redirect(
        admin_url('admin.php?page=yoast-meta-auditor&wpai_installed=1')
    );
    exit;
}

/*--------------------------------------------------  helpers */
function yma_fetch_posts(array $types): array {
    $out = [];
    foreach ($types as $pt) {
        $q = new WP_Query([
            'post_type'      => $pt,
            'posts_per_page' => -1,
            'post_status'    => [ 'publish', 'draft', 'pending' ],
        ]);
        foreach ($q->posts as $p) {
            $out[] = (object) [
                'id'        => $p->ID,
                'title'     => $p->post_title,
                'type'      => $pt,
                'metaTitle' => get_post_meta($p->ID, '_yoast_wpseo_title', true),
                'metaDesc'  => get_post_meta($p->ID, '_yoast_wpseo_metadesc', true),
                'focusKw'   => get_post_meta($p->ID, '_yoast_wpseo_focuskw', true),
                'modified'  => get_the_modified_date('Y-m-d', $p),
            ];
        }
    }
    return $out;
}
function yma_clean(string $v): string  { return html_entity_decode($v, ENT_QUOTES|ENT_HTML5); }
function yma_is_good($p): bool         { return $p->metaTitle !== '' && $p->metaTitle !== '%%sitename%%' && $p->metaDesc !== '' && $p->focusKw !== ''; }
function yma_paginate(int $total, int $current, array $args): string {
    if ($total < 2) return '';
    unset($args['paged']);
    $base = add_query_arg($args, admin_url('admin.php'));
    $links = paginate_links([
        'base'      => $base . '%_%',
        'format'    => '&paged=%#%',
        'current'   => $current,
        'total'     => $total,
        'type'      => 'array',
        'prev_text' => '«',
        'next_text' => '»',
    ]);
    return $links ? '<nav class="yma-pages">'.implode('', $links).'</nav>' : '';
}

/*--------------------------------------------------  render admin page */
function yma_render_page() {

    /* read + persist filters */
    $all_types  = get_post_types([ 'public' => true ], 'objects');
    $stored     = get_option('yoast_meta_auditor_post_types', [ 'page' ]);
    $types_sel  = isset($_GET['post_types'])
        ? array_map('sanitize_text_field', (array) $_GET['post_types'])
        : $stored;
    update_option('yoast_meta_auditor_post_types', $types_sel, false);

    $search = sanitize_text_field($_GET['search']  ?? '');
    $per    = (int) ($_GET['per_page'] ?? 25);
    $paged  = max(1, (int) ($_GET['paged'] ?? 1));
    $fltT   = ! empty($_GET['missing_title']);
    $fltD   = ! empty($_GET['missing_desc']);
    $fltK   = ! empty($_GET['missing_kw']);
    $sort   = $_GET['sort']  ?? 'id';
    $order  = $_GET['order'] ?? 'asc';

    /* fetch + filter */
    $posts = array_filter(yma_fetch_posts($types_sel), function ($p)
            use ($search, $fltT, $fltD, $fltK) {
        $mt = yma_clean($p->metaTitle);
        $md = yma_clean($p->metaDesc);
        $kw = yma_clean($p->focusKw);
        $missT = $mt === '' || $mt === '%%sitename%%';
        $missD = $md === '';
        $missK = $kw === '';

        if ($fltT && ! $missT) return false;
        if ($fltD && ! $missD) return false;
        if ($fltK && ! $missK) return false;

        return $search === '' || stristr($p->title, $search)
            || stristr($mt, $search) || stristr($md, $search)
            || stristr($kw, $search);
    });

    /* sort */
    $valid = [ 'id','title','type','metaTitle','metaDesc','focusKw','modified' ];
    if (in_array($sort, $valid, true)) {
        usort($posts, function ($a, $b) use ($sort, $order) {
            $va = strtolower((string) $a->$sort);
            $vb = strtolower((string) $b->$sort);
            if ($va === $vb) return 0;
            $cmp = $va < $vb ? -1 : 1;
            return $order === 'desc' ? -$cmp : $cmp;
        });
    }

    /* paginate */
    $total  = count($posts);
    $pages  = max(1, (int) ceil($total / $per));
    $paged  = min($paged, $pages);
    $view   = array_slice($posts, ($paged - 1) * $per, $per);

    /* base args for links */
    $base = array_filter([
        'page'          => 'yoast-meta-auditor',
        'search'        => $search,
        'per_page'      => $per,
        'missing_title' => $fltT ? 1 : null,
        'missing_desc'  => $fltD ? 1 : null,
        'missing_kw'    => $fltK ? 1 : null,
        'post_types'    => $types_sel,
    ]);
    $link_sort = fn($col) => esc_url(add_query_arg(
        array_merge($base, [
            'sort'  => $col,
            'order' => ($sort === $col && $order === 'asc') ? 'desc' : 'asc',
        ]),
        admin_url('admin.php')
    ));

    /* WP All Import status */
    $wpai_installed = defined('PMXI_VERSION')
        || is_plugin_active('wp-all-import/wp-all-import.php');
    $install_url = wp_nonce_url(
        admin_url('admin-post.php?action=yma_install_wpai'),
        'yma_install_wpai'
    );
    $new_imp_url = admin_url('edit.php?post_type=wpai-import&page=new');

?>
<style>
:root{--c-t:#2B2F38;--c-p:#4a3df4;--c-p-h:#3a2be0;--c-sec:#e4e2ff}
.yma .button{background:var(--c-p);color:#fff;border:none;border-radius:4px;padding:6px 12px}
.yma .button:hover{background:var(--c-p-h);color:#fff!important}
.yma .button-primary{background:var(--c-t);color:#fff;border-radius:4px;padding:6px 14px}
.yma table.widefat th{background:var(--c-sec)}
.yma table.widefat .good td{background:#d1fae5!important;color:#065f46!important}
.yma table.widefat tbody tr:nth-child(even) td{background:var(--c-sec)!important}
.yma nav.yma-pages{display:flex;justify-content:center;gap:6px;margin-top:18px}
.yma nav.yma-pages a,.yma nav.yma-pages span{padding:6px 12px;border-radius:4px;background:#f0f1f5;color:var(--c-t);text-decoration:none;font-size:13px}
.yma nav.yma-pages a:hover{background:var(--c-p-h);color:#fff}.yma nav.yma-pages .current{background:var(--c-p);color:#fff}
</style>

<div class="wrap yma">
    <h1>Yoast Meta Auditor</h1>

    <form method="get" style="margin-bottom:20px">
        <input type="hidden" name="page" value="yoast-meta-auditor">
        <p>
            <input type="text" name="search" placeholder="Search…" value="<?php echo esc_attr($search); ?>">
            <select name="per_page">
                <?php foreach([10,25,50,100] as $o)
                    printf('<option value="%d"%s>%d / page</option>',
                        $o, selected($per,$o,false), $o); ?>
            </select>
            <label><input type="checkbox" name="missing_title"<?php checked($fltT); ?>> Empty Title</label>
            <label><input type="checkbox" name="missing_desc"<?php checked($fltD); ?>> Empty Desc</label>
            <label><input type="checkbox" name="missing_kw"<?php checked($fltK); ?>> Empty Keyphrase</label>
        </p>

        <p>
            <button type="button" id="yma-all" class="button">Select All Types</button>
            <button type="button" id="yma-none" class="button">Deselect All</button>
        </p>
        <?php foreach($all_types as $slug=>$o)
            printf('<label style="margin-right:15px"><input class="yma-toggle" type="checkbox" name="post_types[]" value="%s"%s> %s</label>',
                esc_attr($slug),
                checked(in_array($slug,$types_sel,true),true,false),
                esc_html($o->labels->name)
            ); ?>

        <p style="margin-top:15px">
            <button class="button-primary">Apply &amp; Save</button>
        </p>
    </form>

    <button id="yma-export" class="button">Download CSV</button>
    <p style="font-size:12px;font-style:italic;margin:6px 0 20px">
        CSV reflects <strong>all</strong> current filters &amp; sort.
    </p>

    <table class="widefat striped">
        <thead><tr><?php
            foreach([
                'id'        => 'ID',
                'title'     => 'Title',
                'type'      => 'Type',
                'metaTitle' => 'Meta Title',
                'metaDesc'  => 'Meta Description',
                'focusKw'   => 'Keyphrase',
                'modified'  => 'Modified',
            ] as $k => $lbl) {
                $arrow = $sort === $k ? ( $order === 'asc' ? ' ▲' : ' ▼' ) : '';
                echo '<th><a href="'.$link_sort($k).'">'.$lbl.$arrow.'</a></th>';
            }
        ?></tr></thead>
        <tbody><?php
            foreach($view as $p){
                echo '<tr'.(yma_is_good($p)?' class="good"':'').'>';
                echo "<td>{$p->id}</td><td>{$p->title}</td><td>{$p->type}</td>";
                echo '<td>'.yma_clean($p->metaTitle).'</td>';
                echo '<td>'.yma_clean($p->metaDesc).'</td>';
                echo '<td>'.yma_clean($p->focusKw).'</td>';
                echo "<td>{$p->modified}</td></tr>";
            }
        ?></tbody>
    </table>

    <?php echo yma_paginate($pages, $paged, array_merge($base, [
        'per_page' => $per,
        'sort'     => $sort,
        'order'    => $order
    ])); ?>

    <hr style="margin:30px 0">

    <h2>WP All Import Helper
        <span style="font-size:12px;font-weight:400">
            — back up before installing/importing.
        </span>
    </h2>
    <p style="display:flex;gap:10px">
        <a href="<?php echo $wpai_installed ? '#' : $install_url; ?>"
           class="button"
           style="pointer-events:<?php echo $wpai_installed?'none':'auto';?>;opacity:<?php echo $wpai_installed?'.6':'1';?>">
            <?php echo $wpai_installed ? 'WP All Import Installed' : 'Install WP All Import'; ?>
        </a>
        <a href="<?php echo $wpai_installed ? $new_imp_url : '#'; ?>"
           class="button"
           style="pointer-events:<?php echo $wpai_installed?'auto':'none';?>;opacity:<?php echo $wpai_installed?'1':'.4';?>">
            Start New Import
        </a>
    </p>
</div><!-- /.wrap -->

<script>
/* toggle helpers */
document.getElementById('yma-all').onclick = () =>
  document.querySelectorAll('.yma-toggle').forEach(cb => cb.checked = true);
document.getElementById('yma-none').onclick = () =>
  document.querySelectorAll('.yma-toggle').forEach(cb => cb.checked = false);

/* ---------- CSV Export (CR+LF, no in-field CR/LF) ---------- */
const DATA = <?php echo wp_json_encode(array_values($posts), JSON_UNESCAPED_UNICODE); ?>;

document.getElementById('yma-export').onclick = () => {
  const header = ['ID','Title','Type','Meta Title','Meta Description','Keyphrase','Modified'];

  const rows = [header,
    ...DATA.map(o => [
      o.id, o.title, o.type,
      decode(o.metaTitle),
      decode(o.metaDesc),
      decode(o.focusKw),
      o.modified
    ])
  ];

  const csv = rows.map(row =>
      row.map(text =>
        `"${String(text)
              .replace(/"/g, '""')      // escape quotes
              .replace(/\r?\n/g, ' ')   // strip CR or LF inside fields
         }"`
       ).join(',')
  ).join('\r\n');                       // CRLF row delimiter

  const blob = new Blob([csv], {type: 'text/csv'});
  const a = Object.assign(document.createElement('a'), {
    href: URL.createObjectURL(blob),
    download: 'yoast-meta-audit.csv'
  });
  a.click();
};

function decode(str){
  const t = document.createElement('textarea');
  t.innerHTML = str;
  return t.value;
}
</script>
<?php
}