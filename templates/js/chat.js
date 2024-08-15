/**
 * Initialize markdown editor.
 */
var simplemde = null;
var simplemdeOps = { 
    autoDownloadFontAwesome: false,
    element: $("#new-msg-text")[0], 
    promptURLs: false,
    forceSync: true, 
    toolbar: false, 
    status: false,
    spellChecker: false,
    shortcuts: {
        "toggleCodeBlock": null, // unbind Ctrl-Alt-C
        "toggleBold": null, // disabled per request
        "cleanBlock": null,
        "toggleHeadingSmaller": null, // disabled per request
        "toggleLink": null, // disabled per request
        "toggleItalic": null, // disabled per request
        "toggleUnorderedList": null, // disabled per request
        "togglePreview": null, // disabled per request
        "toggleCodeBlock": null, // disabled per request
        "drawImage": null,
        "toggleOrderedList": null, // disabled per request
        "toggleHeadingBigger": null, // disabled per request
        "toggleSideBySide": null,
        "toggleFullScreen": null
        }
    };
$(document).ready(function(){
    if($('#feat-markdown-support-enabled').length)
    {
        simplemde = new SimpleMDE(simplemdeOps);
        //$('.CodeMirror').keyup(detectShiftEnter); // disabled per request
    }
});

/**
 * Send AJAX text/important message to the server. 
 * 
 * @param {int} msgImportant  // 0=normal, 1=important
 */
function sendTextMessage(msgImportant) {
    // Exit out early if there is no connection to the server.
    if(serverConnection.active == false) {
        showError('Failed to send message (3).');
        return;
    }

    // Get message text and exit early if empty. 
    var newMsgText = ($('#new-msg-text').val()).trim();
    if(newMsgText.length == 0) {
        return;
    }

    // Disable text field while processing AJAX command.
    $('#new-msg-text').attr('disabled', true);

    // Send AJAX request to save the message. 
    $.ajax({
        url:  BASE_URL + "/ajax",
        type: "POST",
        data: {
            action: 'chat',
            subaction: 'send',
            conversation_id: $('#conversation_id').val(),
            msgBody: newMsgText,
            msgType: msgImportant,
        },
        dataType: 'json',
        success: function(resp) {
            if(resp.success) {
                $('#new-msg-text').val("");
                if($('#feat-markdown-support-enabled').length)
                {
                    simplemde.value("");
                }
                compileMsg(resp, false);
                scrollToBottom();
            }
            else {
                showError('Failed to send message (1).');
            }
        },
        error: function(xhr, ajaxOptions, thrownError) {
            showError('Failed to send message (2).');
        },
    });
    $('#new-msg-text').attr('disabled', false);
    
}

/**
 * Scrolls to the bottom of the chat window.
 */
function scrollToBottom() {
    $('#content').prop("scrollTop", $('#content').prop("scrollHeight") - $('#content').prop("clientHeight"));
}

/**
 * Detect whehter the user pressed SHIFT+ENTER and send the message.
 * 
 * @param {event} Key event.
 */
function detectShiftEnter(event) {
    if(event.keyCode == 13 && event.shiftKey && !event.repeat) {
        //event.preventDefault(); // disabled per request
        //sendTextMessage(); // disabled per request
    }
}

// Handle successful respond after sending a new messge or upload.
function handleAjaxNewMessage(resp) {
    if(resp.success) {
        $('#new-msg-text').val("");
        closeModal();
    }
    else {
        console.error(resp.error);
    }
}

var evtSourceTimeout = setTimeout(handleEventSourceError, 15000);
let serverConnection = {active: true, errorId: undefined};
$(document).ready(function(){
    // Load previous messages first
    loadPrevMsgs();

    // Then start listening for events with new messages. 
    // This prevents cases where we are receiving/parsing messages from AJAX and EventSource at the same time.
    const evtSource = new EventSource(BASE_URL + '/chatstream', { withCredentials: true });
    evtSource.addEventListener("msg", handleEventSourceNewMessage);
    evtSource.addEventListener("notification", handleEventSourceNotification);
    evtSource.addEventListener("delay", handleEventSourceDelay);
    evtSource.addEventListener("thread", handleEventSourceNewThread);
    evtSource.addEventListener("room", handleEventSourceNewRoom);
    evtSource.addEventListener('error', handleEventSourceError);
});


/**
 * Display error if the EventSource connection is lost.
 * 
 * @param {Event} event 
 */
function handleEventSourceError(event) {
    if(serverConnection.active) {
        serverConnection.active = false;
        serverConnection.errorId = showError('Lost server connection. Attempting to reconnect every 10 sec.', true);
    }
}

function handleEventSourceAnnouncement(event) {
    const data = JSON.parse(event.data);
    
}

function handleEventSourceNewRoom(event) {
    const data = JSON.parse(event.data);
    if($('#room-' + data.convo_id).length == 0) {
        var divRoom = document.createElement('div');
        divRoom.setAttribute('id', 'room-' + data.convo_id);
        
        var divRoomName = document.createElement('div');
        divRoomName.classList.add('room');

        var threadsDiv = null;

        if(data.convo_current) {
            divRoomName.classList.add('room-selected');

            if($('#feat-convo-threads-enabled').length) {
                threadsDiv = document.createElement('div');
                threadsDiv.setAttribute('id', 'room-thread-' + data.convo_id);
                threadsDiv.setAttribute('class', 'room-thread');

                var newThread = document.createElement('a');
                newThread.setAttribute('id', 'new-thread');
                newThread.setAttribute('href', '#');
                newThread.setAttribute('onclick', 'openThreadModal()');
                newThread.innerText = '+ New Thread';

                threadsDiv.appendChild(newThread);
            }
            
        }
        
        var divRoomLink = document.createElement('a');
        divRoomLink.setAttribute('href', '%http%%site_url%/chat/' + data.convo_id);
        
        var span = document.createElement('span');
        span.setAttribute('id', 'room-name-' + data.convo_id);
        span.innerHTML = data.convo_name + '&nbsp;';
        divRoomLink.appendChild(span);

        span = document.createElement('span');
        span.setAttribute('id', 'room-new-' + data.convo_id);
        divRoomLink.appendChild(span);

        divRoomName.appendChild(divRoomLink);
        divRoom.appendChild(divRoomName);

        if(threadsDiv != null) {
            divRoom.appendChild(threadsDiv);
        }

        document.getElementById('rooms').appendChild(divRoom);
    }
}

// Wrapper so that the function can be grouped with other thread functions
// and only included if threads are enabled. 
function handleEventSourceNewThread(event) {
    try {
        const data = JSON.parse(event.data);
        addThreadToMenu(data.convo_id, data.thread_id, data.thread_name, data.thread_selected);
    }
    catch (e) {}
}

function handleEventSourceDelay(event) {
    const data = JSON.parse(event.data);
    $('#owlt-value').text(data.delay);
    $('#distance-value').text(data.distance);

    // Expect message every 15 sec. If not received, 
    // assume there is a connection error.
    clearTimeout(evtSourceTimeout);
    evtSourceTimeout = setTimeout(handleEventSourceError, 15000);

    if(serverConnection.errorId  !== undefined) {
        closeError(serverConnection.errorId);
    }
    serverConnection.active = true;
}

function handleEventSourceNewMessage(event) {
    const data = JSON.parse(event.data);
    var scrollContainer = $("#content");
    var autoScroll = scrollContainer.prop("scrollHeight") - scrollContainer.prop("clientHeight") - scrollContainer.prop("scrollTop");
    
    compileMsg(data, false);
    
    if(autoScroll < 200)
    {
        autoScroll = scrollContainer.prop("scrollHeight") - scrollContainer.prop("clientHeight");
        $('#content').animate({
            scrollTop: autoScroll.toString() + 'px'
        }, 250);
    }

    if(data.send_notification == true)
    {
        newMessageNotification(data.author, data.type == 'important');
    }
}

function newMessageNotification(name, important=false, thisRoom=true, ack=false) {
    if($('#feat-audio-notification-enabled').length) {
        $("#new-msg-sound")[0].pause();
        if($('#feat-important-msgs-enabled').length) {
            $('#new-important-msg-sound')[0].pause();
        }

        if(important && $('#feat-important-msgs-enabled').length) {
            $("#new-important-msg-sound")[0].play();
        }
        else{
            $("#new-msg-sound")[0].play();
        }
    }

    if($('#feat-badge-notification-enabled').length) {
        var msg = "Message in '" + name + "'.";
        var opt = {
            icon: '%http%%site_url%/%templates_dir%/media/android-chrome-192x192.png',
            requireInteraction: ack
        };
        if(thisRoom) {
            msg = "Message from " + name;
        } 

        if(!("Notification" in window)) {
            console.log("Browser does not support notifications.")
        }
        else if(Notification.persmission === "granted") {
            var notification = new Notification(msg, opt);
        }
        else if(Notification.persmission !== "denied") {
            Notification.requestPermission().then(function(permission) {
                if(permission === "granted") {
                    var notification = new Notification(msg, opt);
                }
            })
        }
    }
}

function handleEventSourceNotification(event) {
    const data = JSON.parse(event.data);
    if($('#feat-unread-msg-counts-enabled').length && $('#room-new-' + data.conversation_id).length) {
        $('#room-new-' + data.conversation_id).html( '(' + data.num_messages + 
            ((data.num_important > 0) ? '<span class="room-important">&#8252;</span>':'') + ')');
    }

    newMessageNotification($('#room-name-' + data.conversation_id).text(), data.notif_important > 0, false);
    
    if($('#feat-convo-list-order-enabled').length) {
        if($('#room-' + data.conversation_id).length) {
            $('#room-' + data.conversation_id).insertAfter( $('.room-selected').parent() );
        }
        else if($('#feat-convo-threads-enabled').length) {
            $('.room-thread').prepend($('#room-name-' + data.conversation_id).parent());
        }
    }
}



/**
 * Compile message to display on the chat window. 
 * @param {} data 
 * @param {*} before 
 */
function compileMsg(data, before){
    var template = document.querySelector('#msg-sent-template');

    // Avoid duplicating messages if sent twiece by the server.
    if($('#msg-id-' + data.message_id).length > 0) {
        return;
    }

    // Track oldest message received (not by date, but using ids)
    if(oldestMsgRecv == null) {
        oldestMsgRecv = data.message_id;
    }
    else {
        oldestMsgRecv = Math.min(oldestMsgRecv, data.message_id);
    }

    if('content' in document.createElement('template'))
    {
        var msgClone = template.content.cloneNode(true);
        msgClone.querySelector(".msg").setAttribute('id', 'msg-id-' + data.message_id);
        msgClone.querySelector(".msg-from").innerHTML = data.author;
        msgClone.querySelector(".msg-id").textContent = "(" + data.message_id_alt + ")";

        // Add appropriate avatar. Only added for non-logged in user. 
        if(data.source != 'usr') {
            var imgTag = document.createElement("img");
            imgTag.setAttribute('class', 'msg-avatar');
            imgTag.setAttribute('src', '%http%%site_url%/%templates_dir%/media/'.concat(data.avatar));
            msgClone.querySelector('.msg').prepend(imgTag);
        }
        else{
            msgClone.querySelector('.msg').classList.add('response');
        }

        // Message content. Either text or a template for the img/audio/video/file. 
        if(data.type === 'text' || data.type === 'important') {
            msgClone.querySelector(".msg-content").innerHTML = data.message;
            if(data.type === 'important' && $('#feat-important-msgs-enabled').length) {
                msgClone.querySelector(".msg").classList.add("msg-important");
                msgClone.querySelector(".msg-content").classList.add("msg-content-important");
            }
        }
        else {
            // Copy appropriate video, audio, image, or file template. 
            template = document.querySelector('#msg-' + data.type);
            var contentClone = template.content.cloneNode(true);
            try {
                contentClone.querySelectorAll(".file-location").forEach(function(element) {
                    element.src = BASE_URL + "/file/" + data.message_id;
                });
            }
            catch(e) {
                // Do nothing.
            }
            contentClone.querySelector("a").href = BASE_URL + "/file/" + data.message_id;
            contentClone.querySelector(".filename").textContent = data.filename;
            contentClone.querySelector(".filesize").textContent = data.filesize;
            msgClone.querySelector(".msg-content").innerHTML = data.message;
            msgClone.querySelector(".msg-content").appendChild(contentClone);
        }

        msgTime = msgClone.querySelector("time");
        msgTime.setAttribute('status', data.delivered_status);
        msgTime.setAttribute('recv-local',   data.recv_time_local);
        msgTime.setAttribute('recv',   data.recv_time);
        msgTime.setAttribute('sent',   data.sent_time);
        msgTime.setAttribute('msg-id', data.message_id);
        
        msgClone.querySelector(".msg-out-seq").setAttribute('id', 'msg-out-seq-id-' + data.message_id);
        msgClone.querySelector(".msg-out-seq").setAttribute('recipient_crew', 'from' + data.is_crew);
        msgClone.querySelector(".msg-out-seq").setAttribute('sender_crew', 'msg-out-seq-id-' + data.sent_from);

        msgClone.querySelector(".msg-progress-bar").setAttribute('id', 'progress-msg-id-' + data.message_id);
        msgClone.querySelector(".msg-progress-bar-fill").setAttribute('id', 'progress-fill-msg-id-' + data.message_id);
        if($('#feat-progress-bar-enabled').length && data.delivered_status != 'Delivered') {
            msgClone.querySelector('.msg-progress-bar').style.display = "block";
        }
        
        var msgStatus = 'Sent: ' + formatTime(data.sent_time);
        if($('#feat-est-delivery-status-enabled').length) {
            msgStatus += ', Recv: ' + formatTime(data.recv_time) + ', ' + data.delivered_status;
        }
        msgClone.querySelector(".msg-delivery-status").textContent = msgStatus;
        msgClone.querySelector(".msg-delivery-status").setAttribute('id', 'status-msg-id-' + data.message_id);

        // Determine where to add the message within the DOM.
        if(before) { 
            document.querySelector('#msg-container').prepend(msgClone);
        }
        else {
            document.querySelector('#msg-container').appendChild(msgClone);
        }
        updateOutOfSeqWarning();
    }
    else
    {
        // Browser does not support elements. 
    }
}

$(document).ready(setTimeout(updateDeliveryStatus, 1000));

function updateOutOfSeqWarning() {
    var matches = document.querySelectorAll("time");
    var prevSentTime = 0;
    var currSentTime = 0;
    var prevId;
    var currId = 0;
    var index;

    if($('#feat-out-of-seq-enabled').length) {
         for(index = 1; index < matches.length; index++) {

            currId = matches[index].getAttribute('msg-id');
            currSentTime = (new Date(matches[index].getAttribute("sent"))).getTime();
            
            prevIndex = index - 1;
            while(prevIndex >= 0) {
                prevId = matches[prevIndex].getAttribute('msg-id');
                prevSentTime = (new Date(matches[prevIndex].getAttribute("sent"))).getTime();
                prevRecvTime = (new Date(matches[prevIndex].getAttribute("recv"))).getTime();
                
                if(prevSentTime > currSentTime) {
                    document.querySelector('#msg-out-seq-id-' + prevId).style.display = 'inline';
                    document.querySelector('#msg-out-seq-id-' + currId).style.display = 'inline';
                }
                else if(prevRecvTime < currSentTime) {
                    break;
                }

                prevIndex--;
            }
        }
    }
}

function updateDeliveryStatus() {
    var matches = document.querySelectorAll("time[status='Transit']");
    var currTimeObj = new Date();
    var currTime = currTimeObj.getTime();
    var recvTimeObj = null;
    var recvTime = 0;
    var sentTimeObj = null;
    var sentTime = 0;
    var delay;
    var percent = 0;
    var id = 0;
    matches.forEach(function(match) {
        id = match.getAttribute('msg-id');
        
        recvTimeObj = new Date(match.getAttribute("recv"));
        recvTime = recvTimeObj.getTime();
        
        sentTimeObj = new Date(match.getAttribute("sent"));
        sentTime = sentTimeObj.getTime();
        
        delay = recvTime - sentTime;
        percent = 100.0 - (recvTime - currTime) / delay * 100.0;

        document.querySelector('#progress-fill-msg-id-' + id).style.width = percent + '%';
        if(recvTime <= currTime) {
            match.removeAttribute('status');            
            var msgStatus = 'Sent: ' + formatTime(sentTime);
            if($('#feat-est-delivery-status-enabled').length) {
                msgStatus += ', Recv: ' + formatTime(recvTime) + ', Delivered';
            }
            document.querySelector('#status-msg-id-' + id).textContent = msgStatus;
            document.querySelector('#progress-msg-id-' + id).style.display = 'none';
            document.querySelector('#progress-fill-msg-id-' + id).style.display = 'none';
        }
    });
    setTimeout(updateDeliveryStatus, 1000);
}

function closeModal() {
    // Common
    try { $('#progress-video').progressbar('widget').hide('highlight', 0); } catch(e) {}
    try { $('#progress-audio').progressbar('widget').hide('highlight', 0); } catch(e) {}
    try { $('#progress-file').progressbar('widget').hide('highlight', 0);  } catch(e) {}
    $('#video-caption').val("");
    $('#audio-caption').val("");
    $('#file-caption').val("");
    $('.dialog-response').hide('fade', 0);
    try {
        stream.getTracks().forEach(function(track) {
            track.stop();
        });
    }
    catch (e) {} // Do nothing.

    // File
    $('#new-msg-file').val("");

    // Media general
    try {
        playMediaPlayer.src = "";
        playMediaPlayer.removeAttribute('src');
        window.URL.revokeObjectURL(mediaUrl);
    }
    catch (e) {}

    try { $('#dialog-thread').dialog('close'); } catch(e) {}
    $('#dialog-video').dialog('close');
    $('#dialog-audio').dialog('close');
    $('#dialog-file').dialog('close');
}


function openFileModal() {
    // Show error if there is no active connection
    if(serverConnection.active == false) {
        $('.dialog-response').text('Lost server connection. Cannot send files, audio, or video.');
        $('.dialog-response').show('highlight');
    }    
    $('#dialog-file').dialog('open');
}

$(document).ready(function() {
    $(document).tooltip();
});

$(document).ready(function() {
    if($('#feat-convo-list-order-enabled').length) {
        $('#rooms').prepend($('.room-selected').parent())
    }

    if($('#feat-important-msgs-enabled').length) {
        $('#send-btn').css('width', '73px');
        $('#send-btn').css('right', '50px');
    }
    else {
        $('#important-btn').hide();
    }

    // Video
    $('#dialog-video').dialog({
        autoOpen: false,
        draggable: false,
        resizable: false,
        closeOnEscape: false,
        height: 500,
        width: 600,
        position: { my: "center center", at: "center center-25%", of: window },
        buttons: [
            {
                text: 'Start Recording',
                id: 'video-record-btn',
                click: function() { startRecording('video') },
                icon: 'ui-icon-bullet'
            },
            {
                text: 'Stop Recording',
                id: 'video-stop-btn',
                click: function() { stopRecording('video') },
                icon: 'ui-icon-stop'
            },
            {
                text: 'Send Video',
                id: 'video-send-btn',
                click: function() { uploadMedia('video') }
            }
        ],
        modal: true,
        close: closeModal
    });

    // Progress bar for uploads
    $('#progress-video').progressbar({value: false});
    $('#progress-video').progressbar('widget').hide('highlight', 0);

    // Audio
    $('#dialog-audio').dialog({
        autoOpen: false,
        draggable: false,
        resizable: false,
        closeOnEscape: false,
        height: 400,
        width: 600,
        position: { my: "center center", at: "center center-25%", of: window },
        buttons: [
            {
                text: 'Start Recording',
                id: 'audio-record-btn',
                click: function() { startRecording('audio') },
                icon: 'ui-icon-bullet'
            },
            {
                text: 'Stop Recording',
                id: 'audio-stop-btn',
                click: function() { stopRecording('audio') },
                icon: 'ui-icon-stop'
            },
            {
                text: 'Send Audio',
                id: 'audio-send-btn',
                click: function() { uploadMedia('audio') }
            }
        ],
        modal: true,
        close: closeModal
    });    

    // Progress bar for uploads
    $('#progress-audio').progressbar({value: false});
    $('#progress-audio').progressbar('widget').hide('highlight', 0);

    // Files
    $('#dialog-file').dialog({
        autoOpen: false,
        draggable: false,
        resizable: false,
        closeOnEscape: false,
        height: 400,
        width: 600,
        position: { my: "center center", at: "center center-25%", of: window },
        buttons: [
            {
                text: 'Send File',
                id: 'file-send-btn',
                click: function() { uploadMedia('file') }
            }
        ],
        modal: true,
        close: closeModal
    });     

    // Progress bar for uploads
    $('#progress-file').progressbar({value: false});
    $('#progress-file').progressbar('widget').hide('highlight', 0);

   // Files
   $('#dialog-image').dialog({
        autoOpen: false,
        draggable: false,
        resizable: false,
        closeOnEscape: false,
        position: { my: "center center", at: "center center", of: window },
        modal: true,
    });

});

/**
 * Show modal with larger version of the image. 
 * 
 * @param {img} origImg 
 */
function showImage(origImg) {

    // Get larger image placeholder
    var img = document.getElementById('large-msg-img');

    // Calculate ratio to upscale/downscale the image
    let ratio = 0.8 * Math.min(
        window.innerWidth / origImg.naturalWidth, 
        window.innerHeight / origImg.naturalHeight
        );
    
    // Update the image width, height, and set the source. 
    img.width  = ratio * origImg.naturalWidth  * 0.95;
    img.height = ratio * origImg.naturalHeight * 0.95;
    img.src    = origImg.src;

    // Set dimensions for image dialog modal
    $('#dialog-image').dialog('option', 'width',  ratio * origImg.naturalWidth);
    $('#dialog-image').dialog('option', 'height', ratio * origImg.naturalHeight + 35);
    $('#dialog-image').dialog('open');
}

let progressBar;

function uploadMedia(mediaType) {
    // Create object to hold all the form data including the file 
    // or media to be uploaded with the message.
    var formData = new FormData();
    formData.append("conversation_id", $('#conversation_id').val());
    formData.append("action", "chat");
    formData.append("subaction", "upload");

    var captionBox = '#' + mediaType + '-caption';
    formData.append("caption", $(captionBox).val().trim());

    var fileSize = 0;

    // For video messages create a new blob to transfer the data.
    if(mediaType === 'video' || mediaType === 'audio') {
        if(recordedBlobs === undefined) {
            return;
        }
        
        const blobMimeType = (mediaType == 'video') ? 
            'video/webm; codecs="vp8, opus"' : 'audio/ogg; codecs=opus';

        const file = new Blob(recordedBlobs, {type: blobMimeType});
        formData.append("type", mediaType);
        formData.append("data", file, "recording");
        fileSize = file.size;
    }
    // Files can be transferred with the nominal fields. 
    else {
        const file = document.querySelector('#new-msg-file').files[0];
        if(file === undefined) {
            return;
        }
        formData.append("type", "file");
        formData.append("fname", file.name);
        formData.append("fsize", file.size);
        formData.append("data", file, file.name);
        fileSize = file.size;
    }

    // Catch invalid file sizes before failing the upload.
    if(fileSize > $('.MAX_FILE_SIZE').val())
    {
        $('.dialog-response').text('Invalid file size (0 < size < ' + $('.MAX_FILE_SIZE_HUMAN').val() + ')');
        $('.dialog-response').show('highlight');
        return;
    }

    progressBar = $('#progress-' + mediaType)
    $('#progress-' + mediaType).progressbar('widget').show('highlight', 0);

    $.ajax({
        type: "POST",
        url:  BASE_URL + '/ajax',
        async: true,
        data: formData,
        cache: false,
        contentType: false,
        processData: false,
        timeout: 1800000, // 30min
        xhr: function () {
            var myXhr = $.ajaxSettings.xhr();
            $(captionBox).attr('disabled', true);
            if (myXhr.upload) {
                myXhr.upload.addEventListener('progress', progressHandling, {active: false});
            }
            return myXhr;
        },
        success: function(resp) {
            if(resp.success) {
                $('#new-msg-text').val("");
                closeModal();
                console.info("Sent message_id=" + resp.message_id);
                $(captionBox).val("");
                compileMsg(resp, false);
            }
            else {
                $('.dialog-response').text(resp.error);
                $('.dialog-response').show('highlight');
                $('#progress-' + mediaType).progressbar('widget').hide('highlight', 0);
            }
            $(captionBox).attr('disabled', false);
        },
        error: function(jqXHR, textStatus, errorThrown) {
            var errorMsg = 'status=' + ((textStatus == null) ? 'null' : textStatus) + ', ' + 
                           'exception=' + ((errorThrown == null) ? 'null' : errorThrown);
            $('.dialog-response').text('Error uploading file (' + errorMsg + ')');
            $('.dialog-response').show('highlight');
            $('#progress-' + mediaType).progressbar('widget').hide('highlight', 0);
            $(captionBox).attr('disabled', false);
        },
    });
}

function progressHandling(event) {
    var percent = 0;
    var position = event.loaded || event.position;
    var total = event.total;
    if (event.lengthComputable) {
        percent = Math.ceil(position / total * 100);
    }
    progressBar.progressbar('value', percent);
}

var oldMsgQueryInProgress = false;
var hasMoreMessages = true;
var oldestMsgRecv = null;

$(document).ready(function() {
    
    var scrollContainer = document.querySelector('#content');
    // Setup an event listener to poll for older messages.
    scrollContainer.addEventListener('scroll', function(event) {
        if(!oldMsgQueryInProgress && scrollContainer.scrollTop < 300) {
            loadPrevMsgs();
        }
    }, {passive: true});
});



/**
 * Load previous messages in this conversation. 
 * Used for two cases:
 *  1) Loading messages when the page loads. 
 *  2) Loading older messages when the user scrolls up in the conversation. 
 */
function loadPrevMsgs() {

    // The 
    if(serverConnection.active == false) {
        showError('Could not load older messages.')
        return;
    }

    var scrollContainer = document.querySelector('#content');
    var target = document.querySelector('#msg-container');
    var child = target.querySelector('.msg');

    var recvTime = (new Date()).toISOString();
    if(child != null) {
        recvTime = child.querySelector('time').getAttribute('recv-local');
    }

    if(hasMoreMessages) {
        oldMsgQueryInProgress = true;
        $.ajax({
            url:  BASE_URL + '/ajax',
            type: "POST",
            data: {
                action: 'chat',
                subaction: 'prevMsgs',
                conversation_id: $('#conversation_id').val(),
                message_id: (oldestMsgRecv == null) ? -1 : oldestMsgRecv,
                last_recv_time: recvTime,
            },
            dataType: 'json',
            success: function(resp) {
                if(resp.success) {
                    var i;
                    for(i = resp.messages.length-1; i >= 0; i--) {
                        compileMsg(resp.messages[i], true);
                    }
                    scrollContainer.scrollTop = 100;
                    hasMoreMessages = (resp.messages.length > 0);
                }
                else {
                    hasMoreMessages = false;
                }
                if(child == null) {
                    // On-load scroll to the bottom to the newest messages
                    scrollToBottom();
                }
                else {
                    // Scroll to where the user was on the page.
                    scrollContainer.scrollTo(0, child.offsetTop - 80);
                }

                if(!hasMoreMessages || resp.req) {
                    scrollContainer.style.padding = "0px";
                    hasMoreMessages = false;
                    document.querySelector('#msg-container').prepend(document.querySelector('#msg-end').content.cloneNode(true));
                }

                oldMsgQueryInProgress = false;               
            },
            error: function(xhr, ajaxOptions, thrownError) {
                showError('Failed loading previous messages.');
            },
        });
    }
}

/**
 * Show an error message. If not persistent, the error message
 * will automatically disappear after 5sec.
 * 
 * @param {string} msg 
 * @param {boolean} persistent 
 */
function showError(msg, persistent=false) {
    
    var newErrorId = 'msg-error-' + $('.msg-error-text').length + 1;
    var newError = document.createElement('div');
    newError.setAttribute('class', 'msg-error-text');
    newError.setAttribute('id', newErrorId);
    newError.innerHTML = '<strong>ERROR:</strong> ' + msg;
    document.getElementById('msg-error').appendChild(newError);

    console.log('Error "' + msg + '", persistent="' + persistent + '"');


    if(persistent) {
        $('#' + newErrorId).fadeIn('slow');
    }
    else {
        $('#' + newErrorId).fadeIn('slow', function() {
            $(this).delay(5000).fadeOut('slow');
        });
    }

    return newErrorId;
}

/**
 * Remove an error message by the div id.
 * 
 * @param {string} errorId 
 */
function closeError(errorId) {
    var div = document.getElementById(errorId);
    if(div) {
        div.remove();
    }
}