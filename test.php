<script>
// 创建一个Socket实例
var socket = new WebSocket('ws://127.0.0.1:8000'); 

// 打开Socket 
socket.onopen = function(event) { 

  // 发送一个初始化消息
  socket.send('I am the client and I\'m listening!');

  // 监听消息
  socket.onmessage = function(event) { 
    console.log('Client received a message',event); 
  }; 

  // 监听Socket的关闭
  socket.onclose = function(event) { 
    console.log('Client notified socket has closed',event); 
  }; 

  // 关闭Socket.... 
  //socket.close() 
  socket.send('I am the client and I\'m listening!');
  socket.send('I am the client and I\'m listening!');

  window.setTimeout(function() {socket.close();}, 100000);
};
</script>