<?php

use CodePulse2020\MediaUpload\Handlers;
use CodePulse2020\MediaUpload\Scanning;

return [

    /*
    |--------------------------------------------------------------------------
    | 儲存 Disk
    |--------------------------------------------------------------------------
    |
    | 上傳檔案預設寫入的 filesystem disk。此 disk 必須是私有的，若設定為
    | visibility 為 public 的 disk，或 local disk 的根目錄位於 public/
    | 之下，套件會直接拒絕寫入，避免上傳的檔案能被網址直接存取或執行。
    |
    | 支援 S3（需安裝 league/flysystem-aws-s3-v3）。寫入 S3 時不送 ACL，
    | 以相容預設停用 ACL 的 bucket，存取權限請由 bucket policy 控制；檔案
    | 會一併寫入 Content-Type 與 Content-Disposition。
    |
    */

    'disk' => env('MEDIA_UPLOAD_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | 儲存目錄
    |--------------------------------------------------------------------------
    |
    | 檔案在 disk 上的根目錄。實際路徑為 {directory}/{profile}/{年}/{月}/
    | {ulid}.{副檔名}，檔名一律由伺服器產生，絕不使用使用者提供的檔名。
    |
    */

    'directory' => 'uploads',

    /*
    |--------------------------------------------------------------------------
    | 預設 Profile
    |--------------------------------------------------------------------------
    |
    | 呼叫 MediaUpload::store() 或 profile() 未指定名稱時所使用的 profile。
    |
    */

    'default_profile' => 'image',

    /*
    |--------------------------------------------------------------------------
    | 上傳 Profiles
    |--------------------------------------------------------------------------
    |
    | 每個 profile 是一組上傳規則，透過 MediaUpload::profile('avatar') 使用。
    | 必填的鍵為 mimes（允許的真實 MIME）與 max_size（單位 KB）。
    |
    | 選填的鍵有 disk、directory、disposition（inline 或 attachment）、
    | image（覆寫下方的全域圖片設定）、office（覆寫 Office 設定）、
    | visibility 以及 hooks（插入業務邏輯）。未設定的鍵會沿用全域設定。
    | image.resize 的 mode 可為 cover 或 contain。
    |
    | visibility 預設為 private。設為 public 時檔案會放在下方 public 設定的
    | 目錄並提供永久網址，但只允許會被重新編碼的點陣圖；PDF、Office、SVG
    | 等原樣保存的類型一律私有，設定錯誤時啟動即丟出例外。
    |
    | 範例：在 hooks 中加入配額檢查、浮水印與縮圖產生
    |
    |   'product' => [
    |       'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
    |       'max_size' => 8 * 1024,
    |       'hooks' => [
    |           'validated' => [App\Uploads\CheckStorageQuota::class],
    |           'transform' => [
    |               [Watermark::class, ['path' => storage_path('app/logo.png')]],
    |           ],
    |           'after_store' => [App\Uploads\GenerateThumbnails::class],
    |       ],
    |   ],
    |
    */

    'profiles' => [

        'avatar' => [
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'max_size' => 2 * 1024,
            'image' => [
                'max_pixels' => 25_000_000,
                'max_width' => 4096,
                'max_height' => 4096,
                'resize' => ['width' => 512, 'height' => 512, 'mode' => 'cover'],
                'format' => 'webp',
                'quality' => 85,
            ],
        ],

        'image' => [
            'mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            'max_size' => 10 * 1024,
        ],

        'public_image' => [
            'mimes' => ['image/jpeg', 'image/png', 'image/webp'],
            'max_size' => 10 * 1024,
            'visibility' => 'public',
        ],

        'document' => [
            'mimes' => ['application/pdf', 'text/plain'],
            'max_size' => 20 * 1024,
            'disposition' => 'attachment',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | 檔案處理器
    |--------------------------------------------------------------------------
    |
    | 依照從檔案內容偵測出的真實 MIME 選擇處理器。圖片會被重新編碼、SVG 會
    | 被淨化、PDF 會檢查主動內容。未列出的類型由 "*" 對應的處理器負責，
    | 只做驗證而不轉換，提供檔案時一律以下載（attachment）方式回應。
    |
    */

    'handlers' => [
        'image/jpeg' => Handlers\ImageHandler::class,
        'image/png' => Handlers\ImageHandler::class,
        'image/webp' => Handlers\ImageHandler::class,
        'image/gif' => Handlers\ImageHandler::class,
        'image/avif' => Handlers\ImageHandler::class,
        'image/svg+xml' => Handlers\SvgHandler::class,
        'application/pdf' => Handlers\PdfHandler::class,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => Handlers\OfficeHandler::class,
        'application/vnd.ms-excel.sheet.macroEnabled.12' => Handlers\OfficeHandler::class,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => Handlers\OfficeHandler::class,
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => Handlers\OfficeHandler::class,
        '*' => Handlers\PassthroughHandler::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | MIME 與副檔名對照
    |--------------------------------------------------------------------------
    |
    | 真實 MIME 對應允許的副檔名。使用者上傳的副檔名必須與偵測到的類型
    | 相符，否則拒絕。每組中的第一個副檔名會作為輸出時的預設副檔名。
    |
    */

    'mime_map' => [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
        'image/gif' => ['gif'],
        'image/avif' => ['avif'],
        'image/svg+xml' => ['svg'],
        'application/pdf' => ['pdf'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.ms-excel.sheet.macroEnabled.12' => ['xlsm'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'text/plain' => ['txt', 'csv'],
        'text/csv' => ['csv'],
    ],

    /*
    |--------------------------------------------------------------------------
    | 封鎖的副檔名
    |--------------------------------------------------------------------------
    |
    | 只要出現在檔名的任何一段就拒絕（例如 shell.php.jpg），不論 profile
    | 如何設定，作為設定失誤時的最後一道防線。當 svg.enabled 為 true 時，
    | svg 會自動從此清單中移除。
    |
    */

    'blocked_extensions' => [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht', 'phps',
        'html', 'htm', 'xhtml', 'shtml', 'xml', 'js', 'mjs', 'jsp', 'asp', 'aspx',
        'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'msi',
        'dll', 'so', 'jar', 'htaccess', 'ini', 'svg',
    ],

    /*
    |--------------------------------------------------------------------------
    | 圖片處理
    |--------------------------------------------------------------------------
    |
    | 圖片的全域預設值，profile 可個別覆寫。driver 可為 gd 或 imagick。
    | max_pixels、max_width 與 max_height 會在解碼前從檔頭讀取檢查，以防範
    | 解壓縮炸彈。format 為 null 時沿用原格式。strip_metadata 會移除 EXIF
    | 等資訊（包含 GPS），不建議關閉。animation 為 false 時動畫只解碼第一格，
    | 避免大量影格耗盡記憶體；設為 true 時，解碼前會先計算影格數，超過
    | max_frames 或所有影格像素總和超過 max_animation_pixels 就拒絕。
    |
    */

    'image' => [
        'driver' => env('MEDIA_UPLOAD_IMAGE_DRIVER', 'gd'),
        'max_pixels' => 40_000_000,
        'max_width' => 8192,
        'max_height' => 8192,
        'resize' => null,
        'format' => null,
        'quality' => 90,
        'strip_metadata' => true,
        'auto_orient' => true,
        'animation' => false,
        'max_frames' => 100,
        'max_animation_pixels' => 100_000_000,
    ],

    /*
    |--------------------------------------------------------------------------
    | SVG
    |--------------------------------------------------------------------------
    |
    | SVG 本質上是可以內嵌 JavaScript 的 XML，因此預設拒絕。啟用後會使用
    | enshrined/svg-sanitize 移除腳本、事件屬性與外部參照，請先安裝該套件。
    |
    */

    'svg' => [
        'enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | PDF
    |--------------------------------------------------------------------------
    |
    | 含有下列關鍵字的 PDF 會被拒絕，比對前會先解開 "#xx" 跳脫的寫法。
    | 這只是基本防護，壓縮過的 object stream 可能藏住關鍵字，請搭配掃毒，
    | 並以下載方式提供 PDF。
    |
    | /OpenAction 與 /AA 只是觸發點（常用於「開檔時顯示第一頁」），危險的
    | 是它們指向的 /JavaScript 或 /Launch，因此預設不列入，避免誤擋報表。
    |
    */

    'pdf' => [
        'blocked_keywords' => [
            '/JavaScript', '/JS', '/Launch', '/EmbeddedFile',
            '/RichMedia', '/XFA',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Office 文件
    |--------------------------------------------------------------------------
    |
    | xlsx、xlsm、docx、pptx 依壓縮檔內的 [Content_Types].xml 判定真實類型，
    | 不依賴 libmagic 的判斷。以 max_entries 與 max_uncompressed_size
    | （位元組）擋下 zip bomb：解壓大小以實際解出的位元組計算，不採信檔案
    | 宣告的大小，因此每次上傳最多會解壓到這個上限。含巨集的文件預設拒絕，
    | 需要時在 profile 的 office 中設定 allow_macros。需要 zip 擴充套件。
    | 舊版 .xls、.doc 與加密的文件不支援。
    |
    */

    'office' => [
        'allow_macros' => false,
        'max_entries' => 10_000,
        'max_uncompressed_size' => 100 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | 掃毒
    |--------------------------------------------------------------------------
    |
    | 預設不掃描。改為 ClamAvScanner 即可透過 clamd 的 INSTREAM 掃描檔案，
    | socket 可為 unix:// 或 tcp://。fail_closed 為 true 時，掃毒服務無法
    | 連線就拒絕上傳；設為 false 則記錄錯誤後放行。Queue 上傳遇到掃毒服務
    | 無法連線時，會先重試再拒絕。
    |
    | clamav.max_size 必須小於等於 clamd 的 StreamMaxLength（預設 25 MB）。
    | 超過上限、無法掃描的檔案一律拒絕，不受 fail_closed 影響。
    |
    */

    'virus_scan' => [
        'scanner' => env('MEDIA_UPLOAD_SCANNER', Scanning\NullScanner::class),
        'clamav' => [
            'socket' => env('CLAMAV_SOCKET', 'unix:///var/run/clamav/clamd.ctl'),
            'timeout' => 30,
            'max_size' => 25 * 1024 * 1024,
        ],
        'fail_closed' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | 全域 Hooks
    |--------------------------------------------------------------------------
    |
    | 套用到所有 profile，並在 profile 自己的 hooks 之前執行。核心安全步驟
    | 固定不變，hook 只能插在以下時間點：
    |
    | validated    通過大小、副檔名與真實 MIME 檢查之後，可在此做配額檢查
    | transform    圖片解碼後、重新編碼前，可在此加浮水印或裁切（僅限圖片）
    | before_store 編碼與掃毒之後，可修改 directory、filename 與 meta
    | after_store  檔案寫入之後，hook 失敗時會自動刪除剛寫入的檔案
    |
    | 每個 hook 可以是類別名稱、[類別名稱, 參數陣列]，或是實作 Stage /
    | ImageTransformer 的物件。hook 沒有呼叫 $next 時視為拒絕上傳。
    |
    */

    'hooks' => [
        'validated' => [],
        'transform' => [],
        'before_store' => [],
        'after_store' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue 上傳
    |--------------------------------------------------------------------------
    |
    | 使用 storeAsync() 時，檔案會先通過基本檢查並放入隔離區（quarantine），
    | 再由 queue job 完成處理，結果透過 UploadStored 或 UploadRejected 事件
    | 通知。quarantine_ttl 單位為分鐘，供 media-upload:prune 指令清除殘留
    | 檔案使用。
    |
    */

    'queue' => [
        'connection' => env('MEDIA_UPLOAD_QUEUE_CONNECTION'),
        'queue' => env('MEDIA_UPLOAD_QUEUE'),
        'quarantine_disk' => env('MEDIA_UPLOAD_QUARANTINE_DISK', 'local'),
        'quarantine_dir' => 'quarantine',
        'quarantine_ttl' => 60 * 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | 提供檔案
    |--------------------------------------------------------------------------
    |
    | 路由不會自動註冊，請在路由檔呼叫 MediaUpload::routes()（可放在
    | Route::group() 內自訂 prefix、domain 或 middleware）。route、name、
    | middleware 為 routes() 使用的預設值。未註冊路由時，proxy 模式的
    | MediaUpload::url() 會丟出 InvalidConfigException。
    |
    | MediaUpload::url() 會產生具時效的簽章網址，經由此路由回傳檔案並附上
    | 防止 MIME sniffing 與腳本執行的 headers。url_ttl 單位為分鐘。只有
    | inline_mimes 中的類型、且 profile 未設定 attachment 時，才會在瀏覽器內
    | 直接顯示，其餘一律以下載方式回應。下載檔名會改用實際儲存的副檔名。
    |
    | 簽章驗證一定會執行，middleware 只能額外加上（例如 auth、throttle），
    | 無法移除簽章驗證。cache 為 true 時，瀏覽器快取時間不會超過網址剩餘的
    | 有效時間；設為 false 則一律不快取。
    |
    | mode 為 proxy 時，檔案一律經由應用程式回傳，所有 headers 都會套用。
    | mode 為 presigned 時，S3 disk 會改產生 presigned URL 讓瀏覽器直接向
    | S3 下載，可減輕應用程式負擔，但 S3 只能覆寫 Content-Type、
    | Content-Disposition 與 Cache-Control，無法加上 CSP 與 nosniff，建議
    | 搭配獨立網域或 CloudFront 補上。無法產生 presigned URL 的 disk 會自動
    | 改用 proxy。
    |
    */

    'serve' => [
        'mode' => env('MEDIA_UPLOAD_SERVE_MODE', 'proxy'),
        'route' => 'media-upload/files/{path}',
        'name' => 'media-upload.serve',
        'middleware' => [],
        'url_ttl' => 30,
        'headers' => [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
            'Cross-Origin-Resource-Policy' => 'same-site',
        ],
        'cache' => true,
        'inline_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'],
    ],

    /*
    |--------------------------------------------------------------------------
    | 檔名
    |--------------------------------------------------------------------------
    |
    | generator 決定儲存檔名的產生方式，可為 ulid、uuid 或 hash（內容的
    | SHA-256）。使用 hash 時相同內容會共用同一個檔案（去重），而且這類
    | 共用檔案在 after_store hook 失敗時不會被刪除。max_original_length 為
    | 原始檔名清理後保留的最大長度，只會放進回傳結果，不會用於儲存。
    |
    */

    'filename' => [
        'generator' => 'ulid',
        'max_original_length' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | 公開檔案
    |--------------------------------------------------------------------------
    |
    | visibility 為 public 的 profile 會寫入 disk（未設定時沿用上方的 disk）
    | 的 directory 目錄下，讓 bucket policy 或 CDN 只需開放這一個前綴。S3 上
    | 不送 ACL，是否公開由 bucket policy 或 CloudFront 決定。
    |
    | url 為公開網址的開頭，例如 CDN 網域；未設定時使用 disk 本身的 url()。
    | 檔名不會重複，因此 cache_control 預設為長期快取。
    |
    */

    'public' => [
        'disk' => env('MEDIA_UPLOAD_PUBLIC_DISK'),
        'directory' => 'public',
        'url' => env('MEDIA_UPLOAD_PUBLIC_URL'),
        'cache_control' => 'public, max-age=31536000, immutable',
    ],

    /*
    |--------------------------------------------------------------------------
    | 待認領上傳
    |--------------------------------------------------------------------------
    |
    | 兩段式流程使用 ->pending() 上傳時，檔案先放在私有 disk 的 directory
    | 下，取得 token 後再以 MediaUpload::claim() 搬到正式位置。超過 ttl
    | （分鐘）仍未認領的檔案由 media-upload:prune 清除；正式站使用 S3 時，
    | 建議改用 lifecycle rule 清除這個前綴。
    |
    */

    'pending' => [
        'directory' => 'pending',
        'ttl' => 60 * 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | 上傳 Token
    |--------------------------------------------------------------------------
    |
    | StoredFile::token() 產生以 APP_KEY 派生金鑰簽章的 token，內容包含
    | disk、路徑、profile、上傳者與到期時間。ttl 單位為分鐘，需涵蓋使用者
    | 從上傳到送出表單的時間。
    |
    */

    'tokens' => [
        'ttl' => 60 * 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | 可刪除的目錄
    |--------------------------------------------------------------------------
    |
    | MediaUpload::delete() 只會刪除套件管理的目錄（各 profile 目錄、公開、
    | 待認領與隔離區目錄）。若 before_store hook 把檔案放到其他目錄，需要
    | 列在這裡才能刪除。
    |
    */

    'managed_directories' => [],

    /*
    |--------------------------------------------------------------------------
    | 鎖定
    |--------------------------------------------------------------------------
    |
    | 檔名可預期時（hash 產生器、hook 指定的檔名、claim 的目標），寫入前
    | 會以 cache lock 鎖住最終位置，避免同時寫入互相覆蓋。wait 為等待鎖的
    | 秒數，需要支援 lock 的 cache store（redis、database、file 等）。
    |
    */

    'locks' => [
        'wait' => 10,
    ],

];
