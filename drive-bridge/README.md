# Google Drive bridge: Keep runner photos and identity documents on Drive

Source: `drive-bridge/Code.gs`. **This is deployed to a separate Google Apps Script project**, not uploaded to the PHP web directory.

1. Open the existing Google Apps Script project owned by the Drive folder owner.
2. Add a file containing `Code.gs` bridge logic (or create a separate Apps Script project to avoid changing existing `doPost` code).
3. Under Project Settings > Script Properties, add:
   - `BRIDGE_TOKEN`: a random high-entropy token that matches GitHub secret `DRIVE_BRIDGE_TOKEN`
   - `PHOTO_FOLDER_ID`: the existing runner photo folder ID
   - `IDPROOF_FOLDER_ID`: the existing runner ID-document folder ID
4. Deploy as a Web app, running as the Drive folder owner. **Do not place the token in HTML or frontend JavaScript.** An Apps Script web app may still be publicly invokable at its endpoint, so token protection must be kept intact and the token rotated if exposed.
5. Copy `https://script.google.com/macros/s/<deployment-id>/exec` to GitHub secret `DRIVE_BRIDGE_URL`.

Existing Drive links from Excel remain untouched in the MariaDB import. Uploaded files live in Google Drive; no user documents are persisted on the Host.

### Privacy
The bridge intentionally does **not** call `setSharing(ANYONE_WITH_LINK)`. Existing Drive permissions apply. Private ID documents can only be opened by users already authorized by Drive. If the site needs to display photos to other viewers, configure their access explicitly without exposing identity proofs.

### Limitations
Google Apps Script upload from the PHP server requires the web app to accept server-to-server requests, and the backend must have `cURL` available. The host and Apps Script deployment must be tested together before public launch.
