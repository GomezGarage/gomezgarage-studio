<?php
/** Called only inside the disposable MariaDB fixture, using copied admin files. */
function testProductAdmin(PDO $db, string $root, string $private, string $socket): void
{
    $web = $private . '/admin-web';
    mkdir($web); mkdir($web . '/admin'); mkdir($web . '/includes');
    foreach (['catalog.php','product-content.php','admin-product-content.php'] as $name) copy($root . '/includes/' . $name, $web . '/includes/' . $name);
    copy($root . '/admin/product-edit.php', $web . '/admin/product-edit.php');
    $dsn = var_export('mysql:unix_socket=' . $socket . ';dbname=ggs_catalog_fixture;charset=utf8mb4', true);
    file_put_contents($web . '/includes/db.php', '<?php function ggs_db(){static $db;return $db ??= new PDO(' . $dsn . ',"root","",[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);}');
    file_put_contents($web . '/includes/helpers.php', '<?php require_once __DIR__."/db.php";');
    file_put_contents($web . '/includes/auth.php', '<?php require_once __DIR__."/db.php";function ggs_require_admin(){session_start();$_SESSION["admin_id"]=1;}');
    file_put_contents($web . '/includes/admin-header.php', '<!doctype html><html><body>');
    file_put_contents($web . '/includes/admin-footer.php', '</body></html>');
    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $address = stream_socket_get_name($probe, false); fclose($probe);
    $process = proc_open([PHP_BINARY,'-d','upload_max_filesize=100M','-d','post_max_size=110M','-S',$address,'-t',$web],
        [0=>['pipe','r'],1=>['file',$private . '/admin-http.log','a'],2=>['file',$private . '/admin-http.log','a']],$pipes);
    fclose($pipes[0]);
    try {
        for ($i=0;$i<100;$i++) { $probe=@stream_socket_client('tcp://' . $address,$errno,$errstr,.05); if($probe){fclose($probe);break;} usleep(30000); }
        $id = (int) $db->query("SELECT id FROM products WHERE slug='tealight-holder-set-black'")->fetchColumn();
        $url = 'http://' . $address . '/admin/product-edit.php?id=' . $id;
        $html = file_get_contents($url);
        preg_match('/name="csrf" value="([a-f0-9]+)"/', $html, $token);
        $cookie = '';
        $headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : (get_defined_vars()['http_response_header'] ?? []);
        foreach ($headers as $header) if (preg_match('/^Set-Cookie: (PHPSESSID=[^;]+)/i',$header,$m)) $cookie=$m[1];
        dbCheck(!empty($token[1]) && $cookie !== '', 'admin_csrf_form');
        $fields = ['csrf'=>$token[1], 'id'=>(string)$id, 'name_en'=>'Synthetic admin product', 'name_ro'=>'Produs sintetic',
            'desc_short_en'=>'Short', 'desc_short_ro'=>'Scurt', 'category'=>'home', 'material'=>'PLA-CF', 'price_eur'=>'29',
            'stock'=>'2', 'stock_threshold'=>'3', 'status'=>'draft', 'sku'=>'SYNTHETIC-ADMIN',
            'seo_title_en'=>'Synthetic SEO title', 'seo_title_ro'=>'Titlu SEO sintetic',
            'spec_key[]'=>'Height','spec_value[]'=>'10 cm','spec_key_ro[]'=>'Inaltime','spec_value_ro[]'=>'10 cm'];
        $send = static function(array $fields, bool $file) use ($url,$cookie,$root): array {
            $boundary='ggs-admin-fixture'; $body='';
            foreach ($fields as $key=>$value) $body.="--$boundary\r\nContent-Disposition: form-data; name=\"$key\"\r\n\r\n$value\r\n";
            if ($file) $body.="--$boundary\r\nContent-Disposition: form-data; name=\"videos[]\"; filename=\"synthetic.mp4\"\r\nContent-Type: video/mp4\r\n\r\n" . file_get_contents($root . '/tests/catalog/synthetic.mp4') . "\r\n";
            $body.="--$boundary--\r\n";
            $context=stream_context_create(['http'=>['method'=>'POST','header'=>"Cookie: $cookie\r\nContent-Type: multipart/form-data; boundary=$boundary\r\n",'content'=>$body,'ignore_errors'=>true,'follow_location'=>0,'timeout'=>5]]);
            $html=file_get_contents($url,false,$context);
            $headers = function_exists('http_get_last_response_headers') ? http_get_last_response_headers() : (get_defined_vars()['http_response_header'] ?? []);
            return [$headers[0],$html];
        };
        [$status,$body] = $send(array_merge($fields,['csrf'=>'wrong']),true);
        dbCheck(str_contains($body,'form expired'), 'admin_expired_csrf_rejected');
        dbCheck($db->query("SELECT name_en FROM products WHERE id=$id")->fetchColumn() !== 'Synthetic admin product', 'admin_invalid_csrf_no_save');
        [$status,$body] = $send($fields,true);
        dbCheck(str_contains($status,'302'), 'admin_save_redirect');
        $p = ggs_with_product_content($db,[$db->query("SELECT * FROM products WHERE id=$id")->fetch()])[0];
        dbCheck($p['sku']==='SYNTHETIC-ADMIN' && $p['seo_title_ro']==='Titlu SEO sintetic' && (int)$p['stock']===2, 'admin_fields_persist');
        dbCheck($p['specs_ro'][0]['key']==='Inaltime' && count($p['videos'])===1, 'admin_specs_and_video_persist');
        $path = $web . '/' . substr($p['videos'][0]['src'],strlen('/studio/'));
        dbCheck(is_file($path), 'admin_video_file_saved');
        $fields['remove_video[]']='0';
        [$status,$body] = $send($fields,false);
        $p = ggs_with_product_content($db,[$db->query("SELECT * FROM products WHERE id=$id")->fetch()])[0];
        dbCheck(str_contains($status,'302') && $p['videos']===[] && is_file($path), 'admin_video_unlink_retains_file');
        unset($fields['remove_video[]']); $fields['status']='published';
        [$status,$body]=$send($fields,false);
        dbCheck(str_contains($body,'Add a product photograph'), 'admin_publication_requires_photo');
        dbCheck($db->query("SELECT status FROM products WHERE id=$id")->fetchColumn()==='draft', 'admin_failed_publication_stays_draft');
    } finally { proc_terminate($process); proc_close($process); }
}
