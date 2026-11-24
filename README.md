# codepulse/media-upload

Laravel 的安全檔案上傳套件，預設就是安全設定（secure by default）。圖片會被**重新編碼**，所以 polyglot、EXIF/GPS、尾端夾帶的惡意內容都會被清掉。業務邏輯（配額、浮水印、縮圖…）透過 hook 插入，但**無法跳過核心安全步驟**。

- PHP 8.1+、Laravel 10 / 11 / 12
- 需要 `ext-fileinfo`、`ext-gd`（或 `ext-imagick`）；JPEG 自動轉正需要 `ext-exif`

```bash
composer require codepulse/media-upload
php artisan vendor:publish --tag=media-upload-config
```

## 流程

```
ValidateUpload   大小、副檔名白名單、雙副檔名（shell.php.jpg）
SniffMimeType    用 magic bytes 判斷真實 MIME，必須和副檔名一致
  ⤷ [validated]
Decode           圖片：解碼前先檢查像素上限 → 解碼；SVG：sanitize；PDF：檢查主動內容
  ⤷ [transform]  只對圖片：浮水印、裁切…
Encode           重新編碼、去除 metadata
ScanVirus        選配 ClamAV，連不上時預設拒絕（fail closed）
  ⤷ [before_store] 可改目錄、檔名、meta（不能改檔案內容或副檔名）
StoreFile        ULID 檔名、只寫入 private disk
  ⤷ [after_store]  失敗時自動刪除剛寫入的檔案
```

## 使用

```php
use CodePulse\MediaUpload\Facades\MediaUpload;
use CodePulse\MediaUpload\Rules\SafeFile;
use CodePulse\MediaUpload\Transformers\Watermark;

// FormRequest：提早給使用者回饋（只做大小、副檔名、MIME 檢查）
'photo' => ['required', new SafeFile('avatar')],

// 同步
$file = MediaUpload::profile('avatar')->store($request->file('photo'));
$user->update(['avatar_disk' => $file->disk, 'avatar_path' => $file->path]);

// 單次加入業務邏輯
$file = MediaUpload::profile('product')
    ->transform(new Watermark(text: $shop->name, font: resource_path('fonts/Inter.ttf'), position: 'center', opacity: 30))
    ->hook('before_store', new PutUnderShopDirectory($shop))
    ->withContext(['shop_id' => $shop->id])
    ->store($request->file('photo'));

// 提供檔案：簽章 URL，加上 nosniff / CSP / Content-Disposition
$url = $file->url();            // 或 MediaUpload::url($path, disk: 'local')
```

套件**不寫入資料庫**，`StoredFile` 裡有 `disk`、`path`、`mime`、`size`、`sha256`、`originalName`、`width`、`height`、`meta`，請自行保存需要的欄位。

被拒絕時會丟出 `RejectedUploadException`，`$e->reason` 是固定的代碼（例如 `mime_not_allowed`、`image_too_large`），預設會被轉成 HTTP 422 回應。

## Hook

```php
use CodePulse\MediaUpload\Contracts\Stage;
use CodePulse\MediaUpload\Exceptions\RejectedUploadException;
use CodePulse\MediaUpload\UploadContext;

final class CheckStorageQuota implements Stage
{
    public function handle(UploadContext $ctx, Closure $next): UploadContext
    {
        if (Quota::exceeded($ctx->context['user_id'])) {
            throw RejectedUploadException::byHook('儲存空間已滿。');
        }

        return $next($ctx);   // 沒有呼叫 $next 也會被視為拒絕
    }
}
```

- 圖片處理可以實作更簡單的 `ImageTransformer::transform(ImageInterface $image, UploadContext $ctx)`
- 註冊方式有三種：config 中各 profile 的 `hooks`、執行時的 `->hook()` / `->transform()`、全域的 `MediaUpload::extend('validated', ...)`
- 內建 transformer：`Watermark`（圖片或文字、9 宮格位置、平鋪）、`Resize`、`Fit`

## Queue

```php
$pending = MediaUpload::profile('image')->storeAsync($request->file('photo'));
// 監聽 UploadStored / UploadRejected 事件，用 $event->id === $pending->id 對應
```

送進 queue 之前會先同步做便宜的檢查，檔案先放進 quarantine 區再由 job 處理。queue 模式的 hook 必須可序列化（不接受 Closure）。殘留檔可用排程清除：`php artisan media-upload:prune-quarantine`。

## S3

```bash
composer require league/flysystem-aws-s3-v3
```

把 `MEDIA_UPLOAD_DISK` 設成 `s3`（或在 profile 中設定 `disk`）即可。

- 寫入時**不送 ACL**，因此相容預設停用 ACL 的 bucket（BucketOwnerEnforced），存取權限請由 bucket policy 控制。若 disk 明確設定了 `visibility` 或 `ACL`，則照設定送出；但 `public-read`、`public-read-write`、`authenticated-read` 一律拒絕。
- `scoped` disk 會解析到實際的底層 disk 再檢查，底層是 public disk 或位於 `public/` 之下都會拒絕。
- 每個物件都會寫入 `Content-Type` 與 `Content-Disposition`，即使繞過本套件直接讀取也一樣安全。
- `serve.mode = proxy`（預設）：檔案經由應用程式串流，所有安全 headers 都會套用。簽章驗證固定執行，`serve.middleware` 只能額外加上 middleware。
- `serve.mode = presigned`：產生 S3 presigned URL，同時覆寫 `Content-Type`、`Content-Disposition`、`Cache-Control`。S3 無法加上 CSP 和 `nosniff`，建議透過獨立網域或 CloudFront 補上。
- 隔離區（`queue.quarantine_disk`）也可以放在 S3。

## 測試

```bash
composer test        # 不需要 S3，S3 測試會被略過
composer test:s3     # 用 Docker 啟動 AWS Emulator，連同 S3 測試一起跑
```

Imagick 相關測試只在安裝了 `ext-imagick` 時執行（CI 會安裝），沒有安裝時會略過。

`docker/aws-emulator/Dockerfile` 會下載 AWS Emulator 並以 AWS 的 GPG 金鑰驗證簽章。`tests/run-s3-tests.sh` 會啟動容器、取得這次執行專用的金鑰和 CA，再執行 Pest，結束後自動移除容器。S3 測試使用 BucketOwnerEnforced 的 bucket，並檢查寫入請求沒有帶 ACL header。GitHub Actions 中每個 PHP × Laravel 組合都會執行同一支腳本。

## 行為細節

- **下載檔名**：使用原始檔名，但副檔名換成實際儲存的格式（`photo.jpg` 轉成 WebP 後下載為 `photo.webp`）。
- **profile 設為 `disposition: attachment`**：不論 proxy 或 presigned 都以下載方式回應，這個設定寫在簽章裡，無法從網址移除。
- **快取**：瀏覽器快取時間不會超過網址剩餘的有效時間，設定 `serve.cache = false` 則完全不快取。
- **`filename.generator = hash`**：相同內容共用同一個檔案；hook 指定的檔名若已存在，回傳 `path_conflict`，不會覆蓋。可預期的檔名會用 cache lock 避免同時寫入（需要支援 lock 的 cache store）。
- **動畫**：解碼前先解析 GIF/WebP 結構計算影格數；Imagick 另外設定 ImageMagick 的 list-length 上限，並以明確的 coder 讀取（例如 `gif:`），避免 ImageMagick 依內容改用其他 coder。
- **Queue**：同一個上傳 id 的 job 可重複執行而不會重複儲存；掃毒服務暫時無法連線時會先重試；只有事件送出失敗時，不會再發出 `UploadRejected`。
- **掃毒大小上限**：超過 `virus_scan.clamav.max_size` 的檔案一律拒絕（`too_large_to_scan`），不受 `fail_closed` 影響。

## 已知限制

- **PDF 檢查只是基本防護**：壓縮過的 object stream 可以藏住關鍵字。請一律以 attachment 方式提供，並搭配掃毒。
- **SVG 預設關閉**：啟用時需要安裝 `enshrined/svg-sanitize`。
- **Emulator 不等於正式 S3**：AWS Emulator 不會檢查 bucket policy，正式環境的權限設定請另外驗證。
- **動畫預設只保留第一格**：GIF/WebP 動畫只留第一格，可設定 `image.animation`。
