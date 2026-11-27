# Changelog

## [1.1.0] — 2026-11-27

### Added
- `date_directory` 設定（全域，profile 可覆寫）：日期子目錄的 `date()` 格式，預設 `Y/m` 維持原行為；設為 `null` 或空字串則直接放在根目錄下。只接受數字型日期字元並以 `/`、`-`、`_` 分隔，不合法時啟動即丟出 `InvalidConfigException`。
- `UploadBuilder::subdirectory($path)`：存到 profile 目錄底下的子目錄（`{directory}/{subdirectory}/{日期目錄}/{ulid}.{副檔名}`），每段只接受 `[A-Za-z0-9_-]`，不合法時丟出 `RejectedUploadException`（新增 reason `invalid_directory`）。支援 `storeAsync()` 與 `->pending()`；hook 只能在該子目錄內改目錄，也無法改變子目錄本身（`UploadContext::subdirectory()` 唯讀）。
- `UploadRules` 新增 `disk`、`directory`、`dateDirectory`：profile 解析後實際寫入的 disk、不含日期子目錄的根目錄（public profile 含 `public/` 前綴），以及日期子目錄格式，供介面層只依 profile 決定檔案位置。

### Security
- GIF 影格計數改為嚴格依解碼器的格式解析並在無法解析時拒絕（`corrupt_file`）：先前 Graphic Control Extension 宣告非 4 的大小、或影格之間夾雜多餘位元組時，計數會少算，使開啟 `animation` 的 profile 可繞過 `max_frames` 與 `max_animation_pixels`，以極小檔案耗盡 worker 記憶體。
- GIF 每個影格必須落在邏輯畫面內：先前 `getimagesize()` 只讀邏輯畫面尺寸，imagick 驅動下可用超出畫面的影格繞過 `max_pixels`／`max_width`／`max_height`。imagick 讀取時另外套用 WIDTH／HEIGHT 資源上限。
- `subdirectory()` 產生的最終目錄也檢查公開／私有界線：private profile 的目錄若是 public 前綴的上層（例如 `''` 或 `media` 對 `media/public`），先前可用 `subdirectory('public')` 把私有檔放進 public 前綴；現在以 `RejectedUploadException`（`invalid_directory`）拒絕。
- `claim()`／`fromToken()` 在 pending 原檔已不存在時，只有目標檔內容的 SHA-256 與 token 相符才視為「已認領」：先前只要目標路徑存在即回傳，過期的 pending token 可能取得之後佔用同一 hook 檔名的其他擁有者檔案。
- `filename.generator = hash` 時，hook 指定的檔名不得為 64 位十六進位（SHA-256 形式），避免佔用內容定址的位置，使之後相同內容的上傳去重到攻擊者的位元組。
- 內容定址（hash）的檔案不再因 `after_store` hook 失敗而被刪除：先前在 hook 執行期間去重到同一檔案的其他上傳，會被留下指向已刪除檔案的紀錄。
- OOXML 主 part 改由 `_rels/.rels` 的 officeDocument relationship 決定，宣告多個主 part 類型即拒絕；巨集改以 content type（vbaProject、vbaProjectSignature、Excel 4.0 macrosheet）判定，不再只看 `vbaProject.bin` 檔名。先前在 `[Content_Types].xml` 第一個 Override 放誘餌、並改名 VBA part，可將含巨集的文件以 `.xlsx` 存入 `allow_macros = false` 的 profile。

### Changed
- `before_store` hook 設定的目錄必須落在 `delete()` 可刪除的範圍：profile 目錄、`managed_directories`，或 public profile 的 `public/`。先前 private 檔案只要不在 `public/` 下就能寫入任意目錄，存進去之後卻無法用 `delete()` 刪除。hook 寫到 profile 目錄以外（例如 `shops/1`）的專案，需把該目錄加入 `managed_directories`，否則儲存時丟出 `InvalidConfigException`。
- GIF 解析更嚴格：結構不符規格（擴充區塊大小錯誤、區塊截斷、影格超出邏輯畫面）的 GIF 會以 `corrupt_file` 拒絕，少數瀏覽器能容忍的非標準 GIF 可能因此無法上傳。
- OOXML 必須有指向主 part 的 officeDocument relationship（正常 Office 產生的檔案皆有）。

## [1.0.0] — 2026-11-27

### Added
- 公開 profile（`visibility: public`）：只限重新編碼的點陣圖，永久網址可指向 CDN（`public.url`），S3 物件寫入長期快取標頭。
- 上傳 token（`StoredFile::token()`、`MediaUpload::fromToken()`、`Rules\UploadToken`），含擁有者（`ownedBy()`）與期限。
- 待認領區（`->pending()`、`MediaUpload::claim()`）與 `media-upload:prune` 清除未認領檔案。
- `MediaUpload::delete()`，只刪除套件管理的目錄。
- `MediaUpload::rules()` 回傳唯讀 `UploadRules`。
- `storeFromStream()`、`storeFromDisk()`，以及遠端暫存檔（例如 S3 上的 Livewire 暫存檔）支援，複製時即套用大小上限。
- Office Open XML（xlsx、xlsm、docx、pptx）支援：依 `[Content_Types].xml` 判定類型、zip bomb 防護、巨集預設拒絕。
- 所有類別標示 `@api` 或 `@internal`。

### Security
- Office zip bomb 檢查改以實際解壓的位元組計算；先前採信 zip 標頭宣告的大小，可被偽造繞過。`office.max_uncompressed_size` 預設由 200 MB 降為 100 MB。
- `[Content_Types].xml` 只接受 UTF-8，並在解析後拒絕任何 DTD；先前 UTF-16 編碼可繞過 DOCTYPE 檢查。
- 指定 owner 時，沒有擁有者的 token 一律拒絕（先前會放行）。
- `claim()` 改為鎖定最終位置（與 StoreFile 共用），避免兩個待認領檔同時寫入同一個 hook 指定的檔名而互相覆蓋。新增 `locks.wait` 設定。

### Changed
- **提供檔案的路由不再自動註冊**，改由使用者在路由檔呼叫 `MediaUpload::routes(?string $uri = null)`，可以放進任何 `Route::group()`，回傳的 route 也能再串接 `->middleware()`。移除 `serve.enabled`（不呼叫 `routes()` 就等於停用）。沒有註冊路由時，proxy 模式的 `url()` 會丟出 `InvalidConfigException`。
- PDF 預設黑名單移除 `/OpenAction`、`/AA`，並在比對前解開 `#xx` 名稱跳脫。
- 私有 S3 物件寫入 `Cache-Control: private, no-store`。
- `StoredFile` 新增 `visibility`、`pendingTarget`、`owner`；舊資料缺少時視為 private。
- `media-upload:prune-quarantine` 更名為 `media-upload:prune`（舊名保留為別名），列出檔案改為串流。
- `ProcessQuarantinedUpload` 建構子新增 `owner`、`pending` 參數（內部類別）。
