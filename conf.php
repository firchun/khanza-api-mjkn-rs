<?php
    /* ======================================================================
     * LOGGING: semua error PHP dikirim ke log PHP-FPM (yang diteruskan nginx)
     *  - display_errors dimatikan agar output tetap JSON bersih ke BPJS.
     *  - set_error_handler  -> warning/notice/deprecated
     *  - set_exception_handler -> uncaught exception
     *  - register_shutdown_function -> fatal error (E_ERROR/PARSE/COMPILE)
     * ====================================================================== */
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
    @ini_set('log_errors', '1');
    error_reporting(E_ALL);

    // Paksa mode lama: mysqli mengembalikan false + warning (bukan throw exception),
    // agar penanganan error 201/500 di bawah konsisten di semua versi PHP (8.1+ default STRICT).
    mysqli_report(MYSQLI_REPORT_OFF);

    // Simple cache (APCu-first, fallback file untuk dev/local non-APCu)
    function mjknCacheGet($key) {
        if (function_exists('apcu_fetch')) {
            $success = false;
            $value   = apcu_fetch($key, $success);
            return $success ? $value : null;
        }
        $file = sys_get_temp_dir() . '/mjkn_cache_' . md5($key) . '.json';
        if (file_exists($file) && (time() - filemtime($file)) < 3600) {
            $json = @file_get_contents($file);
            if ($json !== false) {
                $data = json_decode($json, true);
                return (json_last_error() === JSON_ERROR_NONE) ? $data : null;
            }
        }
        return null;
    }

    function mjknCacheSet($key, $value, $ttl = 300) {
        if (function_exists('apcu_store')) {
            return @apcu_store($key, $value, $ttl);
        }
        $file = sys_get_temp_dir() . '/mjkn_cache_' . md5($key) . '.json';
        @file_put_contents($file, json_encode($value, JSON_UNESCAPED_UNICODE));
        return true;
    }

    function mjknCacheDelete($key) {
        if (function_exists('apcu_delete')) {
            return @apcu_delete($key);
        }
        $file = sys_get_temp_dir() . '/mjkn_cache_' . md5($key) . '.json';
        if (file_exists($file)) {
            @unlink($file);
        }
        return true;
    }

    function mjknMaskSql($sql) {
        $sql = (string) $sql;
        if (strlen($sql) > 2000) {
            $sql = substr($sql, 0, 2000) . '...<dipotong>';
        }
        // Samarkan literal panjang (mis. token/JWT) agar tidak bocor ke log.
        return preg_replace_callback("/'([^']{40,})'/", function ($m) {
            return "'<disamarkan:" . strlen($m[1]) . "char>'";
        }, $sql);
    }

    function mjknLog($level, $message) {
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '-';
        $uri    = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '-';
        error_log(sprintf('[mjkn][%s] [%s %s] %s', $level, $method, $uri, $message));
    }

    // 2006 = server has gone away, 2013 = lost connection during query.
    function mjknErrnoKoneksi($konektor) {
        return in_array((int) mysqli_errno($konektor), array(2006, 2013), true);
    }

    // Instrumentasi ringan: hitung waktu connect, jumlah query, dan total waktu query
    // per request. Dipakai untuk menemukan sumber lambat (connect / query / jaringan).
    $GLOBALS['__mjkn_request_start'] = isset($GLOBALS['__mjkn_request_start']) ? $GLOBALS['__mjkn_request_start'] : microtime(true);
    $GLOBALS['__mjkn_t'] = array('connect_ms' => 0.0, 'query_ms' => 0.0, 'queries' => 0, 'connects' => 0);

    function mjknQuery($konektor, $sql) {
        $t0     = microtime(true);
        $result = mysqli_query($konektor, $sql);
        $GLOBALS['__mjkn_t']['query_ms'] += (microtime(true) - $t0) * 1000;
        $GLOBALS['__mjkn_t']['queries']++;
        return $result;
    }

    set_error_handler(function ($severity, $message, $file, $line) {
        if (!(error_reporting() & $severity)) {
            return false; // error di-suppress dengan @ -> abaikan
        }
        mjknLog('PHP', sprintf('%s in %s:%d', $message, $file, $line));
        return true;
    });

    set_exception_handler(function ($e) {
        mjknLog('EXCEPTION', sprintf('%s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()) . "\n" . $e->getTraceAsString());
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(array('metadata' => array('message' => 'Internal Server Error', 'code' => 500)));
        }
    });

    register_shutdown_function(function () {
        $err = error_get_last();
        if ($err && in_array($err['type'], array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
            mjknLog('FATAL', sprintf('%s in %s:%d', $err['message'], $err['file'], $err['line']));
        }
    });

    date_default_timezone_set('Asia/Jakarta');

    function loadEnv($path) {
        if (!file_exists($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (strpos($line, '=') === false) {
                continue;
            }
            list($key, $value) = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            if (strlen($value) > 1 && ($value[0] === '"' || $value[0] === "'") && substr($value, -1) === $value[0]) {
                $value = substr($value, 1, -1);
            }
            if (getenv($key) === false) {
                putenv("$key=$value");
                $_ENV[$key] = $value;
            }
        }
    }

    loadEnv(__DIR__ . '/.env');

    define('DB_HOST', getenv('DB_HOST') !== false ? getenv('DB_HOST') : '');
    define('DB_USER', getenv('DB_USER') !== false ? getenv('DB_USER') : '');
    define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
    define('DB_NAME', getenv('DB_NAME') !== false ? getenv('DB_NAME') : '');
    define('APP_URL', getenv('APP_URL') !== false ? rtrim(getenv('APP_URL'), '/') : '');
    // Cache kredensial BPJS untuk mengurangi 1 query/request
    $akunbpjsCacheKey = 'mjkn:akunbpjs';
    $akunbpjsCached   = mjknCacheGet($akunbpjsCacheKey);
    if ($akunbpjsCached === null) {
        $akunbpjs       = fetch_array(bukaquery("select kd_pj,aes_decrypt(usere,'nur') as user,aes_decrypt(passworde,'windi') as pass FROM password_asuransi"));
        $akunbpjsCached = array(
            'user' => $akunbpjs['user'],
            'pass' => $akunbpjs['pass'],
            'kd_pj' => $akunbpjs['kd_pj']
        );
        mjknCacheSet($akunbpjsCacheKey, $akunbpjsCached, 600);
    }
    @define('USERNAME', $akunbpjsCached['user']);
    @define('PASSWORD', $akunbpjsCached['pass']);
    @define('CARABAYAR', $akunbpjsCached['kd_pj']);

    function bukakoneksi() {
        if (isset($GLOBALS['__mjkn_conn']) && $GLOBALS['__mjkn_conn'] instanceof mysqli && !$GLOBALS['__mjkn_conn']->connect_errno) {
            return $GLOBALS['__mjkn_conn'];
        }
        $response = array(
            'metadata' => array(
                'titile' => 'Configurasi Not Found !',
                'message' => 'Gagal terhubung ke database',
                'code' => 500
            )
        );
        $t0        = microtime(true);
        $konektor = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        $GLOBALS['__mjkn_t']['connect_ms'] += (microtime(true) - $t0) * 1000;
        $GLOBALS['__mjkn_t']['connects']++;
        if (!$konektor) {
            mjknLog('DB', sprintf('Koneksi gagal: %s (host=%s db=%s)', mysqli_connect_error(), DB_HOST, DB_NAME));
            http_response_code(500);
            die("" . json_encode($response, true) . "");
        }
        mysqli_set_charset($konektor, 'utf8mb4');
        $GLOBALS['__mjkn_conn'] = $konektor;
        return $konektor;
    }

    function tutupkoneksi() {
        if (isset($GLOBALS['__mjkn_conn']) && $GLOBALS['__mjkn_conn'] instanceof mysqli) {
            @mysqli_close($GLOBALS['__mjkn_conn']);
            $GLOBALS['__mjkn_conn'] = null;
        }
    }

    register_shutdown_function('tutupkoneksi');

    register_shutdown_function(function () {
        $t = isset($GLOBALS['__mjkn_t']) ? $GLOBALS['__mjkn_t'] : null;
        if (!$t) {
            return;
        }
        $total = null;
        if (isset($GLOBALS['request_start'])) {
            $total = (microtime(true) - $GLOBALS['request_start']) * 1000;
        } else if (isset($GLOBALS['__mjkn_request_start'])) {
            $total = (microtime(true) - $GLOBALS['__mjkn_request_start']) * 1000;
        }
        mjknLog('TIMING', sprintf(
            'php_total_ms=%s connect_ms=%.1f connects=%d query_count=%d query_ms=%.1f',
            $total === null ? '?' : round($total, 1),
            $t['connect_ms'],
            $t['connects'],
            $t['queries'],
            $t['query_ms']
        ));
    });
    
    function cleankar($dirty){
        $konektor=bukakoneksi();
	$clean = mysqli_real_escape_string($konektor,$dirty);	
	return preg_replace('/[^a-zA-Z0-9\s_,@. ]/', '',$clean);
    }

    function fetch_array($sql){
        $while = mysqli_fetch_array($sql);
        return $while;
    }

    function fetch_assoc($sql){
        $while = mysqli_fetch_assoc($sql);
        return $while;
    }

    function num_rows($sql){
        $while = mysqli_num_rows($sql);
        return $while;
    }

    // tutupkoneksi sudah dialihkan ke reuse+shutdown

    function bukaquery2($sql){
        $konektor = bukakoneksi();
        $result = mjknQuery($konektor,$sql);
        if ($result === false) {
            mjknLog('SQL', sprintf('Error: %s | Query: %s', mysqli_error($konektor), mjknMaskSql($sql)));
            if (mjknErrnoKoneksi($konektor)) {
                mjknLog('DB', 'Koneksi terputus saat menjalankan query');
                http_response_code(500);
                die(json_encode(array('metadata' => array('message' => 'Gagal terhubung ke database', 'code' => 500))) . "");
            }
        }
        return $result;
    }
    
    function bukaquery3($sql){
        $konektor = bukakoneksi();
        $result = mjknQuery($konektor,$sql);
        if ($result === false) {
            mjknLog('SQL', sprintf('Error: %s | Query: %s', mysqli_error($konektor), mjknMaskSql($sql)));
            if (mjknErrnoKoneksi($konektor)) {
                mjknLog('DB', 'Koneksi terputus saat menjalankan query');
                http_response_code(500);
                die(json_encode(array('metadata' => array('message' => 'Gagal terhubung ke database', 'code' => 500))) . "");
            }
        }
    }

    function getOne2($sql) {
        $hasil = bukaquery2($sql);
        if (!$hasil) {
            return null;
        }
        list($result) = mysqli_fetch_array($hasil);
        return $result;
    }

    function bukaquery($sql) {
        $konektor = bukakoneksi();
        $response = array(
            'metadata' => array(
                'message' => 'Data Sudah Pernah Di Buat Atau Sudah Ada !',
                'code' => 201
            )
        );
        $result = mjknQuery($konektor, $sql);
        if ($result === false) {
            mjknLog('SQL', sprintf('Error: %s | Query: %s', mysqli_error($konektor), mjknMaskSql($sql)));
            if (mjknErrnoKoneksi($konektor)) {
                $response['metadata']['message'] = 'Gagal terhubung ke database';
                $response['metadata']['code']    = 500;
                http_response_code(500);
            } else {
                http_response_code(201);
            }
            die(json_encode($response) . "");
        }
        return $result;
    }
    
    function bukainput($sql) {
        $konektor = bukakoneksi();
        $result = mjknQuery($konektor,$sql);
        if ($result === false) {
            mjknLog('SQL', sprintf('Error: %s | Query: %s', mysqli_error($konektor), mjknMaskSql($sql)));
            die("Gagal menjalankan query !");
        }
        return $result;
    }
    
    function getOne($sql){
        $hasil = bukaquery($sql);
        if (!$hasil) {
            return null;
        }
        list($result) = fetch_array($hasil);
        return $result;
    }

    function escape($string){
        $konektor = bukakoneksi();
        $result = mysqli_real_escape_string($konektor, $string);
        return $result;
    }

    function errnoTerakhir(){
        return mysqli_errno(bukakoneksi());
    }

    function mulaiTransaksi(){
        $konektor = bukakoneksi();
        @mysqli_query($konektor, "SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED");
        return @mysqli_begin_transaction($konektor);
    }

    function commitTransaksi(){
        return @mysqli_commit(bukakoneksi());
    }

    function rollbackTransaksi(){
        return @mysqli_rollback(bukakoneksi());
    }

    // Kunci level sesi agar alokasi nomor antrean tidak balapan antar request.
    function ambilLock($nama, $timeout = 5){
        $konektor = bukakoneksi();
        $namaSafe = mysqli_real_escape_string($konektor, substr($nama, 0, 60));
        $timeout  = intval($timeout);
        return getOne2("select GET_LOCK('$namaSafe', $timeout)");
    }

    function lepasLock($nama){
        $konektor = bukakoneksi();
        $namaSafe = mysqli_real_escape_string($konektor, substr($nama, 0, 60));
        return getOne2("select RELEASE_LOCK('$namaSafe')");
    }

    function noRegPoli($kd_poli,$kd_dokter,$tanggal) {
        // Nomor urut antrean per poli/dokter/tanggal: ambil nilai terbesar antara
        // booking_registrasi dan reg_periksa dalam satu query (sebelumnya 3 query terpisah).
        $sql = "select GREATEST(
                    IFNULL((select MAX(CONVERT(no_reg,signed)) from booking_registrasi where kd_poli='$kd_poli' and kd_dokter='$kd_dokter' and tanggal_periksa='$tanggal'),0),
                    IFNULL((select MAX(CONVERT(no_reg,signed)) from reg_periksa where kd_poli='$kd_poli' and kd_dokter='$kd_dokter' and tgl_registrasi='$tanggal'),0)
                )+1";
        $max    = intval(getOne2($sql));
        if ($max < 1) {
            $max = 1;
        }
        return sprintf("%03s", $max);
    }

    function nextNoRawat($tanggal) {
        if (empty($tanggal)) {
            return '000001';
        }
        $konektor = bukakoneksi();
        $tglSafe  = mysqli_real_escape_string($konektor, $tanggal);
        $prefix   = mysqli_real_escape_string($konektor, str_replace('-', '/', $tglSafe) . '/');
        // Sumber nomor = MAX(referensi_mobilejkn_bpjs, reg_periksa).
        // - referensi_mobilejkn_bpjs tidak pernah dihapus barisnya, sehingga nomor bekas
        //   pembatalan tidak dipakai ulang (nomor antrean tetap rapat/tidak loncat).
        // - reg_periksa dipakai agar tidak bentrok dengan pendaftaran lokal/SIMRS.
        //
        // Batas penomoran hanya prefix tanggal di DALAM no_rawat, bukan kolom tanggal
        // (tanggalperiksa / tgl_registrasi). Data SIMRS bisa punya tanggal yang tidak
        // sama dengan tanggal di no_rawat, mis. registrasi di-ubah tanggalnya di SIMRS
        // (tgl_registrasi 2026-10-21 tapi no_rawat 2026/10/22/000123) atau no_rawat
        // dibuat manual. Kalau kolom tanggal ikut difilter, nomor yang sudah terpakai
        // ikut terlewat, alokasi bentrok dengan PRIMARY KEY no_rawat, INSERT
        // reg_periksa gagal -> permintaan antrean tidak bisa dilanjutkan.
        $sql      = "SELECT GREATEST(
                        IFNULL((SELECT MAX(CAST(SUBSTRING_INDEX(no_rawat, '/', -1) AS UNSIGNED)) FROM referensi_mobilejkn_bpjs WHERE no_rawat LIKE '$prefix%'), 0),
                        IFNULL((SELECT MAX(CAST(SUBSTRING_INDEX(no_rawat, '/', -1) AS UNSIGNED)) FROM reg_periksa WHERE no_rawat LIKE '$prefix%'), 0)
                     ) + 1 AS nxt";
        $res      = mjknQuery($konektor, $sql);
        $nxt      = 1;
        if ($res === false) {
            mjknLog('SQL', sprintf('Error: %s | Query: %s', mysqli_error($konektor), mjknMaskSql($sql)));
        } else if ($row = mysqli_fetch_assoc($res)) {
            $nxt = intval($row['nxt']);
            if ($nxt < 1) {
                $nxt = 1;
            }
        }
        // Pengaman: lewati nomor yang ternyata sudah dipakai (data lama / format tak
        // lazim) supaya INSERT ke reg_periksa tidak pernah bentrok PRIMARY KEY.
        for ($coba = 0; $coba < 20; $coba++) {
            $kandidat = $prefix . sprintf('%06d', $nxt);
            $dipakai  = getOne2("select count(1) from reg_periksa where no_rawat='" . mysqli_real_escape_string($konektor, $kandidat) . "'");
            if (intval($dipakai) < 1) {
                return sprintf('%06d', $nxt);
            }
            mjknLog('ANTREAN', sprintf('no_rawat %s sudah dipakai, dilewati', $kandidat));
            $nxt++;
        }
        return sprintf('%06d', $nxt);
    }

    function nextNoBooking($tanggal) {
        if (empty($tanggal)) {
            return date('Ymd') . '000001';
        }
        $konektor = bukakoneksi();
        $tglSafe  = mysqli_real_escape_string($konektor, $tanggal);
        $tglClean = str_replace('-', '', $tglSafe);
        $prefix   = $tglClean;
        $sql      = "SELECT IFNULL(MAX(CAST(SUBSTRING(nobooking, 9) AS UNSIGNED)), 0) + 1 AS nxt 
                     FROM referensi_mobilejkn_bpjs 
                     WHERE tanggalperiksa = '$tglSafe' 
                       AND nobooking LIKE '" . mysqli_real_escape_string($konektor, $prefix) . "%'";
        $res      = mjknQuery($konektor, $sql);
        $nxt      = 1;
        if ($res === false) {
            mjknLog('SQL', sprintf('Error: %s | Query: %s', mysqli_error($konektor), mjknMaskSql($sql)));
        } else if ($row = mysqli_fetch_assoc($res)) {
            $nxt = intval($row['nxt']);
            if ($nxt < 1) {
                $nxt = 1;
            }
        }
        return $prefix . sprintf('%06d', $nxt);
    }

    function FormatTgl($format, $tanggal){
        return date($format, strtotime($tanggal));
    }

    function hariindo($x){
        $hari = FormatTgl("D", $x);
        switch ($hari) {
            case 'Sun':
                $hari_ini = "Akhad";
                break;
            case 'Mon':
                $hari_ini = "Senin";
                break;
            case 'Tue':
                $hari_ini = "Selasa";
                break;
            case 'Wed':
                $hari_ini = "Rabu";
                break;
            case 'Thu':
                $hari_ini = "Kamis";
                break;
            case 'Fri':
                $hari_ini = "Jumat";
                break;
            case 'Sat':
                $hari_ini = "Sabtu";
                break;
            default:
                $hari_ini = "Tidak di ketahui";
                break;
        }

        return $hari_ini;
    }

    function hash_pass($pass, $int){
        $options = ['cost' => $int];
        return password_hash($pass, PASSWORD_DEFAULT, $options);
    }
    
    function query($sql) {
        global $connection;
        $query = mysqli_query($connection, $sql);
        confirm($query);
        return $query;
    }

    function getToken() {
        global $username, $password;
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode(['username' => USERNAME, 'password' => PASSWORD, 'date' => strtotime(date('Y-m-d H:')) * 1000]);
        $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, 'b155m1774H', true);
        $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
        return $jwt;
    }

    function algoritm($alg){
        $supported_algs = array(
            'ES256' => array('openssl', 'SHA256'),
            'HS256' => array('hash_hmac', 'SHA256'),
            'HS384' => array('hash_hmac', 'SHA384'),
            'HS512' => array('hash_hmac', 'SHA512'),
            'RS256' => array('openssl', 'SHA256'),
            'RS384' => array('openssl', 'SHA384'),
            'RS512' => array('openssl', 'SHA512'),
        );  
        return $supported_algs[$alg];
    }
    
    function urlsafeB64Encode($input){
        return str_replace(['+/', '='], ['-_', ''], base64_encode($input));
    }
    
    function urlsafeB64Decode($input){
        return str_replace(['-_', ''],['+/',  '='], base64_decode($input));
    }
    
    function signnature($msg, $key, $alg = 'HS256'){
        list($function, $algorithm)=algoritm($alg);
        switch ($function) {
            case 'hash_hmac':
                return hash_hmac($algorithm, $msg, $key, true);
        } 
    }
    
    function verify($msg, $signature, $key, $alg){
        if (empty(algoritm($alg))) {
            throw new DomainException('Algorithm not supported');
        }

        list($function, $algorithm) = algoritm($alg);
        switch ($function) {
            case 'hash_hmac':
            default:
                $hash = hash_hmac($algorithm, $msg, $key, true);
                if (function_exists('hash_equals')) {
                    return hash_equals($signature, $hash);
                }
                $len = min(strlen($signature), strlen($hash));

                $status = 0;
                for ($i = 0; $i < $len; $i++) {
                    $status |= (\ord($signature[$i]) ^ \ord($hash[$i]));
                }
                $status |= (strlen($signature) ^ strlen($hash));

                return ($status === 0);
        }
    }

    function encode_jwt($payload,$key,$alg = 'HS256'){
        $header = json_encode(['typ' => 'JWT', 'alg' => $alg]);
        $payload = json_encode($payload);
        $segments = array();
        $segments[] = urlsafeB64Encode($header);
        $segments[] = urlsafeB64Encode($payload);
        $sign_input = implode('.',$segments);
        $signature = signnature($sign_input,$key,$alg);
        $segments[] = urlsafeB64Encode($signature);
        return implode('.',$segments);
    }

    function decode_jwt($token,$key,array $allowed_algs = array()){
        if (empty($key)) {
            throw new InvalidArgumentException('Key may not be empty');
        }
        
        $tks = explode('.', $token);
        if (count($tks) != 3) {
             throw new UnexpectedValueException('Wrong number of segments');
        }
        
        list($headb64, $bodyb64, $cryptob64) = $tks;
        $header =json_decode(urlsafeB64Decode($headb64));
        $payload=json_decode(urlsafeB64Decode($bodyb64));   

        if (null === ($header = json_decode(urlsafeB64Decode($headb64)))) {
            throw new UnexpectedValueException('Invalid header encoding');
        }
        
        if (null === $payload = json_decode(urlsafeB64Decode($bodyb64))) {
            throw new UnexpectedValueException('Invalid claims encoding');
        }
        
        if (false === ($sig = urlsafeB64Decode($cryptob64))) {
            throw new UnexpectedValueException('Invalid signature encoding');
        }
        
        if (empty($header->alg)) {
            throw new UnexpectedValueException('Empty algorithm');
        }
        
        if (empty(algoritm($header->alg))) {
            throw new UnexpectedValueException('Algorithm not supported');
        }
        
        if (!in_array($header->alg, $allowed_algs)) {
            throw new UnexpectedValueException('Algorithm not allowed');
        }
        
        // Check the signature
        if (!verify("$headb64.$bodyb64", $sig, $key, $header->alg)) {
            throw new UnexpectedValueException('Signature verification failed');
        }
        
        // Check if this token has expired.
        if (isset($payload->exp) && (time()-$payload->iat) >= $payload->exp) {
            throw new UnexpectedValueException('Expired token');
        }
        
        return $payload;
    }
    
    function cekuser($username,$password){   
        $cek=false;
        if((!empty($username)) && (!empty($password)) &&(USERNAME==$username) && (PASSWORD==$password)){
            $cek=true;
        }else{
            $cek=false;
        }
        return $cek;
    }

    function createtoken($username,$password){   
        if(cekuser($username,$password)==true){   
            $gtoken=encode_jwt(payloadtoken(),privateKey());
            $response = array(
                'response' => array(
                    'token' => $gtoken
                ),
                'metadata' => array(
                    'message' => 'Ok',
                    'code' => 200
                )
            );
            $status=http_response_code(200);
        }else{
            $response = array(
                'metadata' => array(
                    'message' => 'Username atau Password Tidak Sesuai',
                    'code' => 201
                )
            );
            $status=http_response_code(201);
        }
        return $response;
    }
    
    function cektoken($token){
        try{
            if (decode_jwt($token,privateKey(),['typ' => 'JWT', 'alg' => 'HS256'])) {
                $response =TRUE;
                return $response;
            } 
        }catch(Exception $e){
            $response = array(
                'metadata' => array(
                    'message' => $e->getMessage(),
                    'code' => 201
                )
            );
            http_response_code(201);
            return $response;
        }
    }
    
    function cekpasien($nik,$nopeserta){
        $cacheKey = 'mjkn:pasien:' . md5((string)$nik . '|' . (string)$nopeserta);
        $cached   = mjknCacheGet($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        $data=array();
        $hasil = bukaquery2("SELECT pasien.no_rkm_medis, pasien.no_ktp, pasien.no_peserta,namakeluarga,alamatpj,kelurahanpj,tgl_daftar,kecamatanpj,kabupatenpj,propinsipj,keluarga,TIMESTAMPDIFF(YEAR, pasien.tgl_lahir, CURDATE()) as tahun,(TIMESTAMPDIFF(MONTH, pasien.tgl_lahir, CURDATE()) - ((TIMESTAMPDIFF(MONTH, pasien.tgl_lahir, CURDATE()) div 12) * 12)) as bulan,
                                      TIMESTAMPDIFF(DAY, DATE_ADD(DATE_ADD(pasien.tgl_lahir,INTERVAL TIMESTAMPDIFF(YEAR, pasien.tgl_lahir, CURDATE()) YEAR), INTERVAL TIMESTAMPDIFF(MONTH, pasien.tgl_lahir, CURDATE()) - ((TIMESTAMPDIFF(MONTH, pasien.tgl_lahir, CURDATE()) div 12) * 12) MONTH), CURDATE()) as hari FROM pasien where pasien.no_ktp='$nik' and pasien.no_peserta='$nopeserta'");
        if ($hasil) {
            $row = fetch_array($hasil);
            if ($row) {
                $data = $row;
            }
        }
        mjknCacheSet($cacheKey, $data, 600);
        return $data;
    }
    
    /* ---------------------- Configurasi TOKEN Information ----------------*/
    function privateKey(){
        $key = '123!!abc**';
        return $key;
    }

    function payloadtoken(){
        $token = array(
            "iss" => "Khanza REST API", //Pembuat Token
            "aud" => "Client Khanza REST API", //Penrima Token
            "iat" => time(), //time create Token
            "exp" => 3660, //5 menit {second time} 
            "data" => array( 
                "username" => USERNAME
            )
        );
        return $token;
    }
    
    function validTeks($data){
        $save=str_replace("'","",$data);
        $save=str_replace("\\","",$save);
        $save=str_replace(";","",$save);
        $save=str_replace("`","",$save);
        $save=str_replace("--","",$save);
        $save=str_replace("/*","",$save);
        $save=str_replace("*/","",$save);
        $save=str_replace("text/html","",$save);
        $save=str_replace("<script>","",$save);
        $save=str_replace("</script>","",$save);
        $save=str_replace("<noscript>","",$save);
        $save=str_replace("</noscript>","",$save);
        $save=str_replace("<img","",$save);
        $save=str_replace("document","",$save);
        $save=str_replace(" from ","",$save);
        $save=str_replace("concat","",$save);
        $save=str_replace("union","",$save);
        $save=str_replace("base64","",$save);
        $save=str_replace("//","",$save);
        $save=str_replace("*","",$save);
        $save=str_replace("}","",$save);
        $save=str_replace("$","",$save);
        $save=str_replace("{","",$save);
        $save=str_replace("@","",$save);
        $save=str_replace("[","",$save);
        $save=str_replace("]","",$save);
        $save=str_replace("(","",$save);
        $save=str_replace(")","",$save);
        $save=str_replace("|","",$save);
        $save=str_replace(",","",$save);
        $save=str_replace("<","",$save);
        $save=str_replace(">","",$save);
        $save=str_replace(":","",$save);
        $save=str_replace("+","",$save);
        $save=str_replace("^","",$save);
        $save=str_replace("#","",$save);
        $save=str_replace("!","",$save);
        $save=str_replace("='","",$save);
        $save=str_replace("=/","",$save);
        $save=str_replace("=","",$save);
        $save=str_replace("password","",$save);
        $save=str_replace("submit","",$save);
        $save=str_replace("input","",$save);
        $save=str_replace("meta","",$save);
        $save=str_replace("md5","",$save);
        $save=str_replace("pass","",$save);
        $save=str_replace("SESSION","",$save);
        $save=str_replace("login_shell","",$save);
        $save=str_replace("value","",$save);
        return $save;
    }
    
    function validTeks2($data){
        $save=str_replace("'","",$data);
        $save=str_replace("\\","",$save);
        $save=str_replace(";","",$save);
        $save=str_replace("`","",$save);
        $save=str_replace("--","",$save);
        $save=str_replace("/*","",$save);
        $save=str_replace("*/","",$save);
        $save=str_replace("text/html","",$save);
        $save=str_replace("<script>","",$save);
        $save=str_replace("</script>","",$save);
        $save=str_replace("<noscript>","",$save);
        $save=str_replace("</noscript>","",$save);
        $save=str_replace("<img","",$save);
        $save=str_replace("document","",$save);
        $save=str_replace(" from ","",$save);
        $save=str_replace("concat","",$save);
        $save=str_replace("union","",$save);
        $save=str_replace("base64","",$save);
        $save=str_replace("//","",$save);
        $save=str_replace("*","",$save);
        $save=str_replace("}","",$save);
        $save=str_replace("$","",$save);
        $save=str_replace("{","",$save);
        $save=str_replace("@","",$save);
        $save=str_replace("[","",$save);
        $save=str_replace("]","",$save);
        $save=str_replace("(","",$save);
        $save=str_replace(")","",$save);
        $save=str_replace("|","",$save);
        $save=str_replace(",","",$save);
        $save=str_replace("<","",$save);
        $save=str_replace(">","",$save);
        $save=str_replace("+","",$save);
        $save=str_replace("^","",$save);
        $save=str_replace("#","",$save);
        $save=str_replace("!","",$save);
        $save=str_replace("='","",$save);
        $save=str_replace("=/","",$save);
        $save=str_replace("=","",$save);
        $save=str_replace("password","",$save);
        $save=str_replace("submit","",$save);
        $save=str_replace("input","",$save);
        $save=str_replace("meta","",$save);
        $save=str_replace("md5","",$save);
        $save=str_replace("pass","",$save);
        $save=str_replace("SESSION","",$save);
        $save=str_replace("login_shell","",$save);
        $save=str_replace("value","",$save);
        return $save;
    }
    
    function validTeks3($data,$panjang){
        $save="";
        if(strlen($data)>$panjang){
            header('Location: https://www.google.com');
        }else{
            $save=str_replace("'","",$data);
            $save=str_replace("\\","",$save);
            $save=str_replace(";","",$save);
            $save=str_replace("`","",$save);
            $save=str_replace("--","",$save);
            $save=str_replace("/*","",$save);
            $save=str_replace("*/","",$save);
            $save=str_replace("text/html","",$save);
            $save=str_replace("<script>","",$save);
            $save=str_replace("</script>","",$save);
            $save=str_replace("<noscript>","",$save);
            $save=str_replace("</noscript>","",$save);
            $save=str_replace("<img","",$save);
            $save=str_replace("document","",$save);
            $save=str_replace(" from ","",$save);
            $save=str_replace("concat","",$save);
            $save=str_replace("union","",$save);
            $save=str_replace("base64","",$save);
            $save=str_replace("//","",$save);
            $save=str_replace("*","",$save);
            $save=str_replace("}","",$save);
            $save=str_replace("$","",$save);
            $save=str_replace("{","",$save);
            $save=str_replace("@","",$save);
            $save=str_replace("[","",$save);
            $save=str_replace("]","",$save);
            $save=str_replace("(","",$save);
            $save=str_replace(")","",$save);
            $save=str_replace("|","",$save);
            $save=str_replace(",","",$save);
            $save=str_replace("<","",$save);
            $save=str_replace(">","",$save);
            $save=str_replace(":","",$save);
            $save=str_replace("+","",$save);
            $save=str_replace("^","",$save);
            $save=str_replace("#","",$save);
            $save=str_replace("!","",$save);
            $save=str_replace("='","",$save);
            $save=str_replace("=/","",$save);
            $save=str_replace("=","",$save);
            $save=str_replace("password","",$save);
            $save=str_replace("submit","",$save);
            $save=str_replace("input","",$save);
            $save=str_replace("meta","",$save);
            $save=str_replace("md5","",$save);
            $save=str_replace("pass","",$save);
            $save=str_replace("SESSION","",$save);
            $save=str_replace("login_shell","",$save);
            $save=str_replace("value","",$save);
        }
        return $save;
    }
    
    function validTeks4($data,$panjang){
        $save="";
        if(strlen($data)>$panjang){
            header('Location: https://www.google.com');
        }else{
            $save=str_replace("'","",$data);
            $save=str_replace("\\","",$save);
            $save=str_replace(";","",$save);
            $save=str_replace("`","",$save);
            $save=str_replace("--","",$save);
            $save=str_replace("/*","",$save);
            $save=str_replace("*/","",$save);
            $save=str_replace("text/html","",$save);
            $save=str_replace("<script>","",$save);
            $save=str_replace("</script>","",$save);
            $save=str_replace("<noscript>","",$save);
            $save=str_replace("</noscript>","",$save);
            $save=str_replace("<img","",$save);
            $save=str_replace("document","",$save);
            $save=str_replace(" from ","",$save);
            $save=str_replace("concat","",$save);
            $save=str_replace("union","",$save);
            $save=str_replace("base64","",$save);
            $save=str_replace("//","",$save);
            $save=str_replace("*","",$save);
            $save=str_replace("}","",$save);
            $save=str_replace("$","",$save);
            $save=str_replace("{","",$save);
            $save=str_replace("@","",$save);
            $save=str_replace("[","",$save);
            $save=str_replace("]","",$save);
            $save=str_replace("(","",$save);
            $save=str_replace(")","",$save);
            $save=str_replace("|","",$save);
            $save=str_replace(",","",$save);
            $save=str_replace("<","",$save);
            $save=str_replace(">","",$save);
            $save=str_replace("+","",$save);
            $save=str_replace("^","",$save);
            $save=str_replace("#","",$save);
            $save=str_replace("!","",$save);
            $save=str_replace("='","",$save);
            $save=str_replace("=/","",$save);
            $save=str_replace("=","",$save);
            $save=str_replace("password","",$save);
            $save=str_replace("submit","",$save);
            $save=str_replace("input","",$save);
            $save=str_replace("meta","",$save);
            $save=str_replace("md5","",$save);
            $save=str_replace("pass","",$save);
            $save=str_replace("SESSION","",$save);
            $save=str_replace("login_shell","",$save);
            $save=str_replace("value","",$save);
        }
        return $save;
    }
    
    function validangka($angka){
        if (isset($angka)) {
            if(!is_numeric($angka)) {
                return 0;
            }else{
                return $angka;
            }
        }else{
            return 0;
        }
    }
    
    date_default_timezone_set('Asia/Jakarta');
    $month      = date('Y-m');
    $date       = date('Y-m-d');
    $time       = date('H:i:s');
    $date_time  = date('Y-m-d H:i:s');
?>
