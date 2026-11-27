# Changelog

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
