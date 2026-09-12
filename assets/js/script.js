document.getElementById('enrollForm').addEventListener('submit', function(e){
  e.preventDefault();
  document.getElementById('formSuccess').classList.add('show');
  this.reset();
});
