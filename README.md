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

## 公開檔案

```php
'profiles' => ['product' => ['mimes' => ['image/jpeg', 'image/png', 'image/webp'], 'max_size' => 8192, 'visibility' => 'public']],
```

- **只開放給點陣圖**：public profile 只允許會被重新編碼的點陣圖。PDF、Office、SVG 一律私有，設定錯誤時啟動就會丟出例外。
- **路徑與網址**：檔案放在 `public.directory`（預設 `public/`）之下，`$file->url()` 回傳永久網址，開頭是 `public.url`（例如 CDN 網域）或 disk 的 url。
- **S3 寫入**：不送 ACL，是否公開交給 bucket policy 或 CloudFront，只需開放 `public/*`。物件會寫入 `Cache-Control: public, max-age=31536000, immutable`；私有物件則寫入 `private, no-store`。
- **只存路徑的專案**：可用 `MediaUpload::publicUrl($path)` 組出網址。

## 兩段式上傳：token 與認領

```php
// 1. 上傳 API：先放進待認領區，回傳 token 給前端
$file = MediaUpload::profile('contact')->pending()->ownedBy($member->id)->store($request->file('file'));
return ['token' => $file->token(), 'preview' => $file->url()];

// 2. 送出表單：驗證 token，再認領到正式位置
$request->validate(['attachments.*' => ['string', new UploadToken('contact', owner: fn () => $member->id)]]);
$files = array_map(fn ($t) => MediaUpload::claim($t, 'contact', $member->id), $request->input('attachments'));
```

- **token 內容**：以 APP_KEY 派生的金鑰簽章，包含 disk、路徑、profile、上傳者與到期時間（`tokens.ttl`），無法改指其他路徑。
- **擁有者檢查**：上傳時設了 `ownedBy()`，之後驗證就必須提供相同的 owner；驗證時指定了 owner，沒有擁有者的 token 也一律拒絕。owner 只是一個值，會員與管理員共用 ID 時請加上類型，例如 `ownedBy('member:'.$id)`。
- **token 有簽章但沒有加密**：持有者看得到路徑、原始檔名與 owner，不要把敏感資料放進 owner。
- **重複認領**：`claim()` 可以重複呼叫，會回傳同一個結果。認領前檔案一律放在私有 disk；未認領的檔案由 `media-upload:prune` 清除，正式站使用 S3 時建議改用 lifecycle rule 清除 `pending/`。
- **不用待認領區**：一般上傳也能用 `$file->token()` 搭配 `MediaUpload::fromToken()`，取代「檢查路徑是否存在」的做法。

## 其他 API

- **`MediaUpload::rules('avatar')`**：回傳唯讀的 `UploadRules`（允許的類型、副檔名、大小與尺寸上限），可用來設定表單元件。
- **`->storeFromStream($stream, $name)`、`->storeFromDisk($disk, $path)`**：讓串流或其他 disk 上的檔案經過同一套安全流程。遠端檔案（例如放在 S3 的 Livewire 暫存檔）會先複製到本機，超過大小上限就立即中止。
- **`MediaUpload::delete($file)`**：只刪除套件管理的目錄（profile、公開、待認領、隔離區，以及 `managed_directories` 列出的目錄），任意路徑一律拒絕。hash 去重的檔案需要加上 `force: true`。

> **`storeFromDisk()` 與 `delete()` 只接受伺服器決定的路徑。** 這兩個 API 會照傳入的路徑讀取或刪除。不要把請求參數直接傳進去，否則使用者可以把別人的私有檔重新存進公開 profile，或刪掉同目錄下別人的檔案。請傳入自己產生的路徑，或用 `MediaUpload::fromToken()` 從 token 換回 `StoredFile` 再操作。
- **Office 文件**：xlsx、docx、pptx（xlsm 需 `office.allow_macros`）依 `[Content_Types].xml` 判定類型。zip bomb 檢查以實際解出的位元組計算，不採信檔案宣告的大小，所以每次上傳最多會解壓到 `office.max_uncompressed_size`（預設 100 MB）。需要 `ext-zip`。

## 公開 API

只有標示 `@api` 的類別保證相容（semver）：Facade、`MediaUploader`、`UploadBuilder`、`StoredFile`、`UploadRules`、`PendingUpload`、`UploadContext`、`Contracts\*`、`Exceptions\*`、`Events\*`、`Transformers\*`、`Rules\*`、`Handlers\*`、`Scanning\*`。標示 `@internal` 的類別隨時可能變動，請勿直接依賴。

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

送進 queue 之前會先同步做便宜的檢查，檔案先放進 quarantine 區再由 job 處理。queue 模式的 hook 必須可序列化（不接受 Closure）。殘留檔可用排程清除：`php artisan media-upload:prune`。

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

- **PDF 檢查只是基本防護**：比對前會解開 `#xx` 跳脫，但壓縮過的 object stream 仍可能藏住關鍵字。請一律以 attachment 方式提供，並搭配掃毒。`/OpenAction` 本身不會被擋。
- **`claim()` 不是不可分割的操作**：S3 上是先複製再刪除，中途失敗可能兩邊都留著一份，重新認領會回傳正式位置那份，殘留的待認領檔會被清除。
- **不支援舊版 Office 格式**：`.xls`、`.doc` 和加密的 Office 文件都不支援。
- **SVG 預設關閉**：啟用時需要安裝 `enshrined/svg-sanitize`。
- **Emulator 不等於正式 S3**：AWS Emulator 不會檢查 bucket policy，正式環境的權限設定請另外驗證。
- **動畫預設只保留第一格**：GIF/WebP 動畫只留第一格，可設定 `image.animation`。
