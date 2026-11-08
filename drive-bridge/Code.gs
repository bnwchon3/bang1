/**
 * Deploy separately as a Google Apps Script Web App owned by the Drive folder owner.
 * Script Properties:
 *   BRIDGE_TOKEN = long random secret (same as GitHub DRIVE_BRIDGE_TOKEN)
 *   PHOTO_FOLDER_ID = folder id from existing project
 *   IDPROOF_FOLDER_ID = folder id from existing project
 *
 * Deploy > New deployment > Web app > Execute as: Me
 * URL (/exec) is GitHub DRIVE_BRIDGE_URL.
 * Network authorization is checked with a shared secret; never embed that secret in client JS.
 */
function doPost(e) {
  function out(obj) {
    return ContentService.createTextOutput(JSON.stringify(obj))
      .setMimeType(ContentService.MimeType.JSON);
  }
  try {
    var p=JSON.parse(e.postData.contents || '{}');
    var prop=PropertiesService.getScriptProperties();
    var expected=prop.getProperty('BRIDGE_TOKEN');
    if (!expected || String(p.token||'') !== expected) return out({success:false,message:'Unauthorized'});
    if (!/^[1-9][0-9]*$/.test(String(p.runnerId||'')))return out({success:false,message:'Invalid runner ID'});
    if (p.type!=='photo' && p.type!=='idproof')return out({success:false,message:'Invalid type'});
    var mime=String(p.mimeType||'');
    if(['image/jpeg','image/png','application/pdf'].indexOf(mime)<0||(p.type==='photo' && mime==='application/pdf'))return out({success:false,message:'Invalid MIME type'});
    var bytes=Utilities.base64Decode(p.base64Data||'');
    if(!bytes.length||bytes.length>6*1024*1024)return out({success:false,message:'Invalid file length'});
    var folderId=prop.getProperty(p.type==='photo'?'PHOTO_FOLDER_ID':'IDPROOF_FOLDER_ID');
    if(!folderId)throw new Error('Folder not configured');
    var folder=DriveApp.getFolderById(folderId);
    var ext=mime==='image/jpeg'?'.jpg':mime==='image/png'?'.png':'.pdf';
    var safeName='runner_'+String(p.runnerId)+'_'+p.type+'_'+Date.now()+ext;
    var blob=Utilities.newBlob(bytes,mime,safeName);
    var file=folder.createFile(blob);
    // Do not make documents or images public automatically. Control Drive sharing separately.
    return out({success:true,url:'https://drive.google.com/file/d/'+file.getId()+'/view',fileId:file.getId()});
  } catch (ex) {
    console.error(ex);
    return out({success:false,message:'Upload failed'});
  }
}
